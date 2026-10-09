<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Auth;

use Grpc\BaseStub;
use Grpc\ChannelCredentials;
use InvalidArgumentException;
use Ondewo\Sip\Auth\BearerTokenAuthenticator;
use Ondewo\Sip\Auth\ClientConfig;
use Ondewo\Sip\Tests\Tls\TestPki;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionParameter;
use Stringable;

/**
 * The checks ClientConfig makes before anything reaches ext-grpc, its target, its channel options
 * and its redaction. The real handshakes are in tests/Tls/MutualTlsHandshakeTest.php.
 */
#[CoversClass(ClientConfig::class)]
final class ClientConfigTest extends TestCase
{
    private static TestPki $pki;

    public static function setUpBeforeClass(): void
    {
        self::$pki = new TestPki();
    }

    public function testTheDefaultsAreServerAuthenticatedTlsWithTheSystemRoots(): void
    {
        $config = new ClientConfig('sip.example.com', 443);

        self::assertTrue($config->useSecureChannel);
        self::assertSame('', $config->grpcCert);
        self::assertFalse($config->hasClientIdentity());
        self::assertInstanceOf(ChannelCredentials::class, $config->channelCredentials());
    }

    public function testMutualTlsKeepsBothHalvesOfTheIdentity(): void
    {
        $config = $this->mutualTlsConfig();

        self::assertTrue($config->hasClientIdentity());
        self::assertSame(self::$pki->clientCert, $config->grpcClientCert);
        self::assertSame(self::$pki->clientKey, $config->grpcClientKey());
        self::assertInstanceOf(ChannelCredentials::class, $config->channelCredentials());
    }

    public function testEmptyStringsOnBothHalvesMeanNoClientIdentity(): void
    {
        $config = new ClientConfig('localhost', 50051, self::$pki->caCert, '', '');

        self::assertFalse($config->hasClientIdentity());
        self::assertInstanceOf(ChannelCredentials::class, $config->channelCredentials());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function halfPairs(): iterable
    {
        yield 'certificate only' => ['grpcClientCert', true];
        yield 'key only' => ['grpcClientKey', false];
    }

    #[DataProvider('halfPairs')]
    public function testHalfAClientIdentityIsRefusedBeforeGrpc(string $theHalfThatIsSet, bool $certificateOnly): void
    {
        try {
            new ClientConfig(
                'localhost',
                50051,
                self::$pki->caCert,
                $certificateOnly ? self::$pki->clientCert : '',
                $certificateOnly ? '' : self::$pki->clientKey,
            );
            self::fail('half a client identity was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame(
                'ClientConfig for localhost:50051 has only ' . $theHalfThatIsSet . ' set; set both grpcClientCert '
                . 'and grpcClientKey for mutual TLS, or neither.',
                $error->getMessage(),
            );
            self::assertNoSecretIn($error->getMessage());
        }
    }

    public function testAClientIdentityOnAnInsecureChannelIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'ClientConfig for localhost:50051 has a client certificate but useSecureChannel is false; '
            . 'a client identity needs a TLS channel.'
        );

        new ClientConfig('localhost', 50051, '', self::$pki->clientCert, self::$pki->clientKey, false);
    }

    public function testChannelCredentialsRechecksAConfigThatBypassedTheConstructor(): void
    {
        // unserialize() builds an object without running the constructor: channelCredentials() is
        // the only path to ext-grpc, so it repeats the check rather than letting grpc-core abort.
        $serialized = serialize($this->mutualTlsConfig());
        $certificate = self::$pki->clientCert;
        $forged = str_replace(
            's:14:"grpcClientCert";s:' . strlen($certificate) . ':"' . $certificate . '";',
            's:14:"grpcClientCert";s:0:"";',
            $serialized,
        );
        self::assertNotSame($serialized, $forged);
        $config = unserialize($forged);
        self::assertInstanceOf(ClientConfig::class, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has only grpcClientKey set');

        $config->channelCredentials();
    }

    public function testThePrivateKeyParameterIsMarkedSensitive(): void
    {
        // PHP >= 8.2 replaces the argument in every stack trace with a SensitiveParameterValue.
        $parameter = new ReflectionParameter([ClientConfig::class, '__construct'], 'grpcClientKey');

        self::assertCount(1, $parameter->getAttributes('SensitiveParameter'));
    }

    public function testAnInsecureChannelHasNullCredentialsAndLogsAWarningNamingHostAndPort(): void
    {
        $logger = $this->recordingLogger();
        $config = new ClientConfig('10.0.0.5', 50051, useSecureChannel: false);

        self::assertNull($config->channelCredentials($logger));
        self::assertSame(
            [['warning', 'Using an insecure gRPC channel to 10.0.0.5:50051: traffic is not encrypted.']],
            $logger->records,
        );
    }

    public function testWithoutALoggerTheInsecureWarningGoesToErrorLog(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ondewo-error-log-');
        self::assertIsString($file);
        $previous = ini_set('error_log', $file);
        try {
            self::assertNull((new ClientConfig('::1', 50051, useSecureChannel: false))->channelCredentials());
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $logged = (string) file_get_contents($file);
        unlink($file);

        self::assertStringContainsString('Using an insecure gRPC channel to [::1]:50051', $logged);
    }

    /**
     * @return iterable<string, array{string, int|string, string}>
     */
    public static function targets(): iterable
    {
        yield 'host name' => ['sip.example.com', 443, 'sip.example.com:443'];
        yield 'IPv4' => ['127.0.0.1', 50051, '127.0.0.1:50051'];
        yield 'string port' => ['localhost', '50051', 'localhost:50051'];
        yield 'IPv6 loopback' => ['::1', 50051, '[::1]:50051'];
        yield 'IPv6' => ['2001:db8::7', 443, '[2001:db8::7]:443'];
        yield 'scoped IPv6' => ['fe80::1%eth0', 50051, '[fe80::1%eth0]:50051'];
        yield 'already bracketed' => ['[::1]', 50051, '[::1]:50051'];
        yield 'ipv6 scheme' => ['ipv6:[::1]', 50051, 'ipv6:[::1]:50051'];
        yield 'dns scheme' => ['dns:///sip.example.com', 443, 'dns:///sip.example.com:443'];
    }

    #[DataProvider('targets')]
    public function testTheTargetBracketsOnlyBareIpv6Literals(string $host, int|string $port, string $target): void
    {
        self::assertSame($target, (new ClientConfig($host, $port))->target());
    }

    public function testTheDefaultChannelOptionsMatchTheOtherOndewoSdks(): void
    {
        self::assertSame([
            'grpc.max_send_message_length' => 2147483647,
            'grpc.max_receive_message_length' => 2147483647,
            'grpc.keepalive_time_ms' => 30000,
            'grpc.keepalive_timeout_ms' => 20000,
            'grpc.http2.ping_timeout_ms' => 20000,
            'grpc.keepalive_permit_without_calls' => 0,
            'grpc.http2.max_pings_without_data' => 2,
            'grpc.max_reconnect_backoff_ms' => 5000,
        ], ClientConfig::DEFAULT_CHANNEL_OPTIONS);
    }

    public function testChannelOptionsAreTheDefaultsTheCallerOptionsAndTheCredentials(): void
    {
        $authenticator = new BearerTokenAuthenticator('a-token');
        $options = $this->mutualTlsConfig()->channelOptions([
            'update_metadata' => $authenticator,
            'grpc.keepalive_time_ms' => 60000,
            'grpc.ssl_target_name_override' => 'sip.example.internal',
        ]);

        self::assertInstanceOf(ChannelCredentials::class, $options['credentials']);
        self::assertSame($authenticator, $options['update_metadata']);
        self::assertSame(60000, $options['grpc.keepalive_time_ms']);
        self::assertSame('sip.example.internal', $options['grpc.ssl_target_name_override']);
        self::assertSame(2, $options['grpc.http2.max_pings_without_data']);
    }

    public function testChannelOptionsOfAnInsecureConfigCarryANullCredentialsKey(): void
    {
        $options = (new ClientConfig('localhost', 50051, useSecureChannel: false))
            ->channelOptions(logger: $this->recordingLogger());

        // \Grpc\BaseStub throws unless the key EXISTS; null is what createInsecure() returns.
        self::assertArrayHasKey('credentials', $options);
        self::assertNull($options['credentials']);
    }

    public function testCredentialsPassedAsAnOptionAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('channelOptions() for localhost:50051 received a "credentials" option');

        (new ClientConfig('localhost', 50051))->channelOptions(['credentials' => ChannelCredentials::createSsl()]);
    }

    public function testAGeneratedStubAcceptsTheChannelOptions(): void
    {
        // Every generated <Service>Client extends BaseStub with this constructor; channels connect
        // lazily, so nothing here touches the network.
        $stub = new BaseStub('localhost:50051', $this->mutualTlsConfig()->channelOptions());
        try {
            self::assertStringContainsString('localhost:50051', $stub->getTarget());
        } finally {
            $stub->close();
        }
    }

    public function testEveryRenderingRedactsThePrivateKey(): void
    {
        $config = $this->mutualTlsConfig();
        ob_start();
        var_dump($config);
        $dumped = (string) ob_get_clean();

        foreach (
            [
                'string' => (string) $config,
                'print_r' => print_r($config, true),
                'var_dump' => $dumped,
                'json_encode' => (string) json_encode($config),
            ] as $rendering => $text
        ) {
            self::assertStringContainsString(ClientConfig::REDACTED, $text, $rendering);
            self::assertStringNotContainsString(self::keyBody(self::$pki->clientKey), $text, $rendering);
        }
        self::assertInstanceOf(Stringable::class, $config);
        self::assertSame(ClientConfig::REDACTED, $config->jsonSerialize()['grpcClientKey']);
        self::assertStringStartsWith(ClientConfig::class . '({"host":"localhost","port":50051,', (string) $config);
    }

    public function testAnEmptyPrivateKeyRendersEmptyNotRedacted(): void
    {
        $config = new ClientConfig('localhost', 50051);

        self::assertSame([
            'host' => 'localhost',
            'port' => 50051,
            'grpcCert' => '',
            'grpcClientCert' => '',
            'grpcClientKey' => '',
            'useSecureChannel' => true,
        ], $config->__debugInfo());
        self::assertStringNotContainsString(ClientConfig::REDACTED, (string) $config);
    }

    private function mutualTlsConfig(): ClientConfig
    {
        return new ClientConfig(
            'localhost',
            50051,
            self::$pki->caCert,
            self::$pki->clientCert,
            self::$pki->clientKey,
        );
    }

    private static function assertNoSecretIn(string $text): void
    {
        self::assertStringNotContainsString('-----BEGIN', $text);
        self::assertStringNotContainsString(self::keyBody(self::$pki->clientKey), $text);
    }

    /** The base64 body of a PEM: what must never appear anywhere. */
    private static function keyBody(string $pem): string
    {
        return explode("\n", $pem)[1];
    }

    private function recordingLogger(): AbstractLogger
    {
        return new class () extends AbstractLogger {
            /** @var list<array{mixed, string}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
    }
}
