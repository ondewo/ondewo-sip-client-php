<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Auth;

use Ondewo\Sip\Auth\ClientConfig;
use Ondewo\Sip\Auth\CurlKeycloakTokenTransport;
use Ondewo\Sip\Auth\KeycloakAuthenticationException;
use Ondewo\Sip\Auth\KeycloakTokenProvider;
use Ondewo\Sip\Auth\KeycloakTokenProviderRegistry;
use Ondewo\Sip\Auth\KeycloakTokenResponse;
use Ondewo\Sip\Auth\KeycloakTransportException;
use Ondewo\Sip\Tests\Tls\TestPki;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The default transport over a REAL socket, against keycloak_test_server.py (python3; another
 * interpreter via ONDEWO_TLS_TEST_PYTHON): the form on the wire, status and body pass-through,
 * connection refused, the timeout, TLS verification on and off, and the whole provider and
 * ClientConfig path end to end with no injected seam.
 */
#[CoversClass(CurlKeycloakTokenTransport::class)]
#[CoversClass(KeycloakTokenResponse::class)]
#[CoversClass(KeycloakTokenProvider::class)]
#[CoversClass(KeycloakTokenProviderRegistry::class)]
#[CoversClass(ClientConfig::class)]
final class CurlKeycloakTokenTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    /** @var array<int, resource> */
    private static array $serverPipes = [];

    private static string $directory = '';

    /** @var array{http: int, https: int} */
    private static array $ports;

    public static function setUpBeforeClass(): void
    {
        $pki = new TestPki();
        $directory = sys_get_temp_dir() . '/ondewo-keycloak-test-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('cannot create ' . $directory);
        }
        self::$directory = $directory;
        file_put_contents("$directory/server.pem", $pki->serverCert);
        file_put_contents("$directory/server.key", $pki->serverKey);
        chmod("$directory/server.key", 0600);
        $python = getenv('ONDEWO_TLS_TEST_PYTHON') ?: 'python3';
        $process = proc_open(
            [$python, __DIR__ . '/keycloak_test_server.py', json_encode(['cert' => "$directory/server.pem", 'key' => "$directory/server.key"])],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', "$directory/server.log", 'a']],
            $pipes,
        );
        if ($process === false) {
            throw new RuntimeException("cannot start $python");
        }
        self::$server = $process;
        self::$serverPipes = $pipes;
        $read = [$pipes[1]];
        $write = $except = null;
        $line = stream_select($read, $write, $except, 30) === 1 ? fgets($pipes[1]) : false;
        $ports = is_string($line) ? json_decode($line, true) : null;
        if (!is_array($ports) || !isset($ports['http'], $ports['https'])) {
            $log = (string) @file_get_contents("$directory/server.log");
            self::tearDownAfterClass();
            throw new RuntimeException("the Keycloak test server did not start ($python is required):\n" . $log);
        }
        self::$ports = $ports;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$serverPipes as $pipe) {
            fclose($pipe);
        }
        self::$serverPipes = [];
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        foreach (glob(self::$directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir(self::$directory);
    }

    protected function tearDown(): void
    {
        KeycloakTokenProviderRegistry::setProviderFactory(null);
    }

    public function testAFormPostReachesTheEndpointAndTheAnswerComesBackUnchanged(): void
    {
        self::script([['status' => 400, 'body' => '{"error":"invalid_grant"}']]);

        $response = (new CurlKeycloakTokenTransport())->post(
            self::url('http') . '/realms/r/protocol/openid-connect/token',
            ['grant_type' => 'password', 'username' => 'a b&c=d', 'password' => 'p+w%'],
            10.0,
        );

        self::assertSame(400, $response->statusCode);
        self::assertSame('{"error":"invalid_grant"}', $response->body());
        self::assertSame([[
            'method' => 'POST',
            'path' => '/realms/r/protocol/openid-connect/token',
            'content_type' => 'application/x-www-form-urlencoded',
            'form' => [['grant_type', 'password'], ['username', 'a b&c=d'], ['password', 'p+w%']],
        ]], self::requests());
    }

    public function testAConnectionRefusedIsATransportException(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $this->expectException(KeycloakTransportException::class);
        $this->expectExceptionMessageMatches('/^curl error 7: /');
        (new CurlKeycloakTokenTransport())->post("http://$address/token", [], 2.0);
    }

    public function testTheTimeoutBoundsTheWholeExchange(): void
    {
        self::script([['status' => 200, 'body' => '{}', 'sleep' => 3.0]]);
        $start = hrtime(true);
        try {
            (new CurlKeycloakTokenTransport())->post(self::url('http') . '/token', [], 0.3);
            self::fail('no timeout');
        } catch (KeycloakTransportException $exception) {
            self::assertStringStartsWith('curl error 28: ', $exception->getMessage());
        }

        self::assertLessThan(2.0, (hrtime(true) - $start) / 1e9);
    }

    public function testAnUntrustedCertificateIsRefusedWhenVerifying(): void
    {
        self::script([['status' => 200, 'body' => '{}']]);

        $this->expectException(KeycloakTransportException::class);
        $this->expectExceptionMessageMatches('/^curl error (60|35|58): /');
        (new CurlKeycloakTokenTransport(verifySsl: true))->post(self::url('https') . '/token', [], 5.0);
    }

    public function testVerifySslFalseAcceptsASelfSignedKeycloak(): void
    {
        self::script([['status' => 200, 'body' => '{"ok":true}']]);

        $response = (new CurlKeycloakTokenTransport(verifySsl: false))->post(self::url('https') . '/token', ['a' => 'b'], 5.0);

        self::assertSame(200, $response->statusCode);
        self::assertSame('{"ok":true}', $response->body());
    }

    public function testTheCurlOptionsCarryTheTimeoutAndTlsVerification(): void
    {
        $verifying = (new CurlKeycloakTokenTransport())->curlOptions('https://kc/token', ['a' => 'b'], 10.0);
        $insecure = (new CurlKeycloakTokenTransport(false))->curlOptions('https://kc/token', [], 0.0001);

        self::assertSame(10000, $verifying[CURLOPT_TIMEOUT_MS]);
        self::assertSame(10000, $verifying[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertTrue($verifying[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $verifying[CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($verifying[CURLOPT_FOLLOWLOCATION]);
        self::assertSame('a=b', $verifying[CURLOPT_POSTFIELDS]);
        self::assertFalse($insecure[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(0, $insecure[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(1, $insecure[CURLOPT_TIMEOUT_MS], 'a sub-millisecond timeout is never 0 (= no timeout)');
    }

    public function testTheResponseNeverRendersItsBody(): void
    {
        $response = new KeycloakTokenResponse(200, '{"access_token":"acc-secret-9"}');

        self::assertSame(['statusCode' => 200, 'bodyLength' => 31], $response->__debugInfo());
        self::assertStringNotContainsString('acc-secret-9', print_r($response, true));
    }

    public function testTheProviderLogsInAndRefreshesOverTheDefaultTransport(): void
    {
        self::script([
            ['status' => 200, 'body' => '{"access_token":"acc-1","expires_in":0,"refresh_token":"off-1"}'],
            ['status' => 503, 'body' => '{"error":"temporarily_unavailable"}'],
        ]);
        $provider = new KeycloakTokenProvider(self::url('http') . '/auth/', 'my-realm', 'sdk', 'user', 'pw', logger: new RecordingLogger());

        try {
            $provider->authorizationMetadata();
            self::fail('an expired token was served');
        } catch (KeycloakAuthenticationException $exception) {
            self::assertStringStartsWith('Keycloak token refresh failed with status 503', $exception->getMessage());
        }
        $requests = self::requests();
        self::assertSame('/auth/realms/my-realm/protocol/openid-connect/token', $requests[0]['path']);
        self::assertSame([['grant_type', 'password'], ['client_id', 'sdk'], ['username', 'user'], ['password', 'pw'], ['scope', 'offline_access']], $requests[0]['form']);
        self::assertSame([['grant_type', 'refresh_token'], ['client_id', 'sdk'], ['refresh_token', 'off-1']], $requests[1]['form']);
    }

    public function testAnUnreachableKeycloakFailsTheProviderWithTheTypedError(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        try {
            new KeycloakTokenProvider("http://$address", 'r', 'c', 'u', 'p');
            self::fail('no error');
        } catch (KeycloakAuthenticationException $exception) {
            self::assertStringStartsWith(
                'Keycloak token login could not reach the token endpoint (KeycloakTransportException: curl error 7: ',
                $exception->getMessage(),
            );
            self::assertInstanceOf(KeycloakTransportException::class, $exception->getPrevious());
        }
    }

    public function testClientConfigEndToEndWithAnOfflineToken(): void
    {
        self::script([['status' => 200, 'body' => '{"access_token":"acc-e2e","expires_in":300,"refresh_token":"off-rotated"}']]);
        KeycloakTokenProviderRegistry::clear();
        $config = new ClientConfig(
            'localhost',
            50051,
            useSecureChannel: false,
            keycloakUrl: self::url('https'),
            realm: 'ondewo-ccai-platform',
            clientId: 'ondewo-nlu-cai-sdk-public',
            username: 'user@example.com',
            password: 'pw-never-sent',
            verifySsl: false,
            refreshToken: 'off-handed-e2e',
        );

        $options = $config->channelOptions(logger: new RecordingLogger());

        self::assertSame(['authorization' => ['Bearer acc-e2e']], ($options['update_metadata'])([]));
        self::assertSame(
            [[['grant_type', 'refresh_token'], ['client_id', 'ondewo-nlu-cai-sdk-public'], ['refresh_token', 'off-handed-e2e']]],
            array_column(self::requests(), 'form'),
        );
        KeycloakTokenProviderRegistry::clear();
    }

    private static function url(string $scheme): string
    {
        return $scheme . '://127.0.0.1:' . self::$ports[$scheme];
    }

    /**
     * @param list<array{status: int, body: string, sleep?: float}> $entries
     */
    private static function script(array $entries): void
    {
        self::control('POST', '/__script', (string) json_encode($entries));
    }

    /**
     * @return list<array{method: string, path: string, content_type: string, form: list<array{string, string}>}>
     */
    private static function requests(): array
    {
        return json_decode(self::control('GET', '/__requests', null), true);
    }

    private static function control(string $method, string $path, ?string $body): string
    {
        $handle = curl_init(self::url('http') . $path);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 10]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $answer = curl_exec($handle);
        if (!is_string($answer)) {
            throw new RuntimeException('the Keycloak test server did not answer: ' . curl_error($handle));
        }

        return $answer;
    }
}
