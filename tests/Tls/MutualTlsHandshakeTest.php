<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Tls;

use Google\Protobuf\GPBEmpty;
use Grpc\BaseStub;
use Ondewo\Sip\Auth\ClientConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Real TLS and mutual-TLS handshakes of ClientConfig::channelOptions() against real gRPC servers.
 *
 * PHP has no gRPC server, so tls_test_server.py (python3 with grpcio; another interpreter via
 * ONDEWO_TLS_TEST_PYTHON) serves two ports with a PKI generated here: "tls" (server-authenticated)
 * and "mtls" (requires a client certificate of the test CA). Neither registers a service: an RPC
 * answered UNIMPLEMENTED reached the server, so the handshake succeeded; UNAVAILABLE is a failed
 * handshake.
 */
final class MutualTlsHandshakeTest extends TestCase
{
    /** Registered nowhere: the servers answer it UNIMPLEMENTED once the handshake succeeded. */
    public const METHOD = '/ondewo.test.Handshake/Ping';

    public const DEADLINE_MICROSECONDS = 10_000_000;

    private static TestPki $pki;

    private static string $directory;

    /** @var resource|null */
    private static $server;

    /** @var array<int, resource> */
    private static array $serverPipes = [];

    /** @var array{tls: int, mtls: int} */
    private static array $ports;

    public static function setUpBeforeClass(): void
    {
        self::$pki = new TestPki();
        $directory = sys_get_temp_dir() . '/ondewo-tls-test-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('cannot create ' . $directory);
        }
        self::$directory = $directory;
        foreach (
            [
                'ca.pem' => self::$pki->caCert,
                'server.pem' => self::$pki->serverCert,
                'server.key' => self::$pki->serverKey,
            ] as $name => $pem
        ) {
            file_put_contents($directory . '/' . $name, $pem);
            chmod($directory . '/' . $name, 0600);
        }
        $spec = [
            'tls' => ['cert' => "$directory/server.pem", 'key' => "$directory/server.key", 'client_ca' => null],
            'mtls' => ['cert' => "$directory/server.pem", 'key' => "$directory/server.key", 'client_ca' => "$directory/ca.pem"],
        ];
        $python = getenv('ONDEWO_TLS_TEST_PYTHON') ?: 'python3';
        $process = proc_open(
            [$python, __DIR__ . '/tls_test_server.py', json_encode($spec)],
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
        if (!is_array($ports) || !isset($ports['tls'], $ports['mtls'])) {
            $log = (string) @file_get_contents("$directory/server.log");
            self::tearDownAfterClass();
            throw new RuntimeException(
                "the TLS test server did not start ($python with grpcio is required; set ONDEWO_TLS_TEST_PYTHON "
                . "to use another interpreter):\n" . $log
            );
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

    public function testServerAuthenticatedTlsWithTheTestCa(): void
    {
        self::assertHandshakeSucceeds($this->config('tls', grpcCert: self::$pki->caCert));
    }

    public function testEmptyClientCertificateAndKeyMeanPlainTls(): void
    {
        $config = $this->config('tls', grpcCert: self::$pki->caCert, grpcClientCert: '', grpcClientKey: '');

        self::assertFalse($config->hasClientIdentity());
        self::assertHandshakeSucceeds($config);
    }

    public function testMutualTls(): void
    {
        self::assertHandshakeSucceeds($this->mutualTlsConfig('mtls'));
    }

    public function testAMutualTlsServerRejectsAClientWithoutACertificate(): void
    {
        self::assertHandshakeFails($this->config('mtls', grpcCert: self::$pki->caCert));
    }

    public function testAServerCertificateOfAnotherCaFailsTheHandshake(): void
    {
        self::assertHandshakeFails($this->config('tls', grpcCert: self::$pki->otherCaCert));
    }

    public function testAnEmptyGrpcCertTrustsTheSystemRootsNotTheTestCa(): void
    {
        self::assertHandshakeFails($this->config('tls'));
    }

    public function testAClientCertificateOfAnUnrelatedCaIsRejected(): void
    {
        self::assertHandshakeFails($this->config(
            'mtls',
            grpcCert: self::$pki->caCert,
            grpcClientCert: self::$pki->otherClientCert,
            grpcClientKey: self::$pki->otherClientKey,
        ));
    }

    public function testCrlfPemsWork(): void
    {
        $crlf = static fn (string $pem): string => str_replace("\n", "\r\n", $pem);

        self::assertHandshakeSucceeds($this->config(
            'mtls',
            grpcCert: $crlf(self::$pki->caCert),
            grpcClientCert: $crlf(self::$pki->clientCert),
            grpcClientKey: $crlf(self::$pki->clientKey),
        ));
    }

    public function testMutualTlsOverIpv6Loopback(): void
    {
        $probe = @stream_socket_client('tcp://[::1]:' . self::$ports['mtls'], $errno, $error, 2);
        if ($probe === false) {
            self::markTestSkipped("this host has no IPv6 loopback ($error)");
        }
        fclose($probe);
        $config = $this->mutualTlsConfig('mtls', host: '::1');

        self::assertSame('[::1]:' . self::$ports['mtls'], $config->target());
        self::assertHandshakeSucceeds($config);
    }

    private function mutualTlsConfig(string $server, string $host = '127.0.0.1'): ClientConfig
    {
        return $this->config(
            $server,
            $host,
            grpcCert: self::$pki->caCert,
            grpcClientCert: self::$pki->clientCert,
            grpcClientKey: self::$pki->clientKey,
        );
    }

    private function config(
        string $server,
        string $host = '127.0.0.1',
        string $grpcCert = '',
        string $grpcClientCert = '',
        string $grpcClientKey = '',
    ): ClientConfig {
        return new ClientConfig($host, self::$ports[$server], $grpcCert, $grpcClientCert, $grpcClientKey);
    }

    private static function assertHandshakeSucceeds(ClientConfig $config): void
    {
        $status = self::call($config);
        self::assertSame(\Grpc\STATUS_UNIMPLEMENTED, $status->code, 'handshake failed: ' . $status->details);
    }

    private static function assertHandshakeFails(ClientConfig $config): void
    {
        self::assertSame(\Grpc\STATUS_UNAVAILABLE, self::call($config)->code);
    }

    private static function call(ClientConfig $config): \stdClass
    {
        // A fresh channel per call: ext-grpc would otherwise reuse a persistent channel whose
        // handshake an earlier test already made.
        $stub = new class ($config->target(), $config->channelOptions(['force_new' => true])) extends BaseStub {
            public function ping(): \stdClass
            {
                [, $status] = $this->_simpleRequest(
                    MutualTlsHandshakeTest::METHOD,
                    new GPBEmpty(),
                    [GPBEmpty::class, 'decode'],
                    [],
                    ['timeout' => MutualTlsHandshakeTest::DEADLINE_MICROSECONDS],
                )->wait();

                return $status;
            }
        };
        try {
            return $stub->ping();
        } finally {
            $stub->close();
        }
    }
}
