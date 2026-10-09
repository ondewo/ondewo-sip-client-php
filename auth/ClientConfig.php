<?php

declare(strict_types=1);

namespace Ondewo\Sip\Auth;

use Grpc\ChannelCredentials;
use InvalidArgumentException;
use JsonSerializable;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Where and how a generated client stub connects: the target, TLS or mutual TLS, and the channel
 * options shared by every ONDEWO SDK.
 *
 * The three certificate fields hold PEM CONTENT, never a file path; reading the files is the
 * caller's job (`file_get_contents()`):
 *
 * ```php
 * $config = new \Ondewo\Sip\Auth\ClientConfig(
 *     host: 'sip.example.com',
 *     port: 443,
 *     grpcCert: file_get_contents('certs/ca.pem'),
 *     grpcClientCert: file_get_contents('certs/client.pem'), // both, or neither
 *     grpcClientKey: file_get_contents('certs/client.key'),
 * );
 * $client = new \Ondewo\Sip\SipClient($config->target(), $config->channelOptions([
 *     'update_metadata' => new \Ondewo\Sip\Auth\BearerTokenAuthenticator($token),
 * ]));
 * ```
 *
 * The client identity is checked BEFORE anything reaches ext-grpc: grpc-core aborts the whole
 * PHP process on a private key without its certificate, and silently drops a certificate without
 * its key. No message, `__toString()`, `var_dump()` / `print_r()` (`__debugInfo()`) or
 * `json_encode()` rendering of this object carries the private key.
 */
final class ClientConfig implements JsonSerializable, Stringable
{
    /** What every rendering of this object shows instead of a non-empty `grpcClientKey`. */
    public const REDACTED = '***REDACTED***';

    /** 2**31 - 1: the largest message gRPC can be told to accept, in both directions. */
    public const MAX_MESSAGE_LENGTH = 2147483647;

    /**
     * The channel arguments of every ONDEWO SDK (the same values as ondewo-client-utils-python).
     * ext-grpc takes int or string values only, so `false` is spelled `0`.
     *
     * Keepalive pings run only during active calls and stop after 2 pings without data: a default
     * grpc-core server answers a client that keeps pinging a silent stream with GOAWAY
     * "too_many_pings". An unanswered ping counts the connection as dead after
     * `grpc.http2.ping_timeout_ms` (the socket's TCP_USER_TIMEOUT is `grpc.keepalive_timeout_ms`).
     * The reconnect backoff is capped at 5 s instead of gRPC's 120 s.
     */
    public const DEFAULT_CHANNEL_OPTIONS = [
        'grpc.max_send_message_length' => self::MAX_MESSAGE_LENGTH,
        'grpc.max_receive_message_length' => self::MAX_MESSAGE_LENGTH,
        'grpc.keepalive_time_ms' => 30000,
        'grpc.keepalive_timeout_ms' => 20000,
        'grpc.http2.ping_timeout_ms' => 20000,
        'grpc.keepalive_permit_without_calls' => 0,
        'grpc.http2.max_pings_without_data' => 2,
        'grpc.max_reconnect_backoff_ms' => 5000,
    ];

    private string $grpcClientKey;

    /**
     * @param string     $host             host name, IPv4 or IPv6 literal (bracketed by target()),
     *                                     or a gRPC target with a scheme (`dns:`, `unix:`, ...)
     * @param int|string $port             the server port
     * @param string     $grpcCert         PEM of the CA (or server certificate) to trust; empty
     *                                     trusts the platform's default roots
     * @param string     $grpcClientCert   PEM of the client certificate chain for mutual TLS
     * @param string     $grpcClientKey    PEM of the client certificate's private key
     * @param bool       $useSecureChannel false selects a plaintext channel (not for production)
     *
     * @throws InvalidArgumentException if exactly one of grpcClientCert and grpcClientKey is set,
     *                                  or a client identity is set on an insecure channel
     */
    public function __construct(
        public readonly string $host,
        public readonly int|string $port,
        public readonly string $grpcCert = '',
        public readonly string $grpcClientCert = '',
        #[\SensitiveParameter] string $grpcClientKey = '',
        public readonly bool $useSecureChannel = true,
    ) {
        $this->grpcClientKey = $grpcClientKey;
        $this->assertClientIdentity();
    }

    /** The PEM private key of the client certificate (empty without mutual TLS). */
    public function grpcClientKey(): string
    {
        return $this->grpcClientKey;
    }

    /** True when both halves of the client identity are set (mutual TLS). */
    public function hasClientIdentity(): bool
    {
        return $this->grpcClientCert !== '' && $this->grpcClientKey !== '';
    }

    /**
     * The `host:port` target a generated client stub is constructed with. A bare IPv6 literal is
     * bracketed (`::1` becomes `[::1]:50051`); a bracketed host or one with a scheme is left alone.
     */
    public function target(): string
    {
        $address = explode('%', $this->host, 2)[0];
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '[' . $this->host . ']:' . $this->port;
        }

        return $this->host . ':' . $this->port;
    }

    /**
     * The channel credentials for this configuration: TLS (with a client identity when both halves
     * are set) or `null` for a plaintext channel, which is what
     * `\Grpc\ChannelCredentials::createInsecure()` itself returns. A plaintext channel is logged
     * as a warning naming `host:port`: on `$logger` when one is given, else through `error_log()`.
     *
     * @throws InvalidArgumentException if the client identity is incomplete, or set on an insecure
     *                                  channel (re-checked here: this is the only path to ext-grpc)
     */
    public function channelCredentials(?LoggerInterface $logger = null): ?ChannelCredentials
    {
        $this->assertClientIdentity();
        if (!$this->useSecureChannel) {
            $message = sprintf('Using an insecure gRPC channel to %s: traffic is not encrypted.', $this->target());
            if ($logger !== null) {
                $logger->warning($message);
            } else {
                error_log($message);
            }

            return null;
        }

        // Empty means unset: ext-grpc takes "" as a root PEM that loads no certificate at all, so
        // only null selects the default roots, and only null on both selects "no identity".
        return ChannelCredentials::createSsl(
            $this->grpcCert !== '' ? $this->grpcCert : null,
            $this->hasClientIdentity() ? $this->grpcClientKey : null,
            $this->hasClientIdentity() ? $this->grpcClientCert : null,
        );
    }

    /**
     * The `$opts` array a generated `*Client` stub is constructed with: the ONDEWO channel defaults,
     * then `$options` (which win, e.g. `update_metadata` or `grpc.ssl_target_name_override`), then
     * the `credentials` of this configuration.
     *
     * @param array<string, mixed> $options extra stub options and channel arguments
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException if `$options` carries its own `credentials`: TLS material
     *                                  goes through this configuration, so that it is checked
     */
    public function channelOptions(array $options = [], ?LoggerInterface $logger = null): array
    {
        if (array_key_exists('credentials', $options)) {
            throw new InvalidArgumentException(
                'channelOptions() for ' . $this->target() . ' received a "credentials" option; '
                . 'set grpcCert, grpcClientCert, grpcClientKey and useSecureChannel on ClientConfig instead.'
            );
        }

        return ['credentials' => $this->channelCredentials($logger)] + $options + self::DEFAULT_CHANNEL_OPTIONS;
    }

    public function __toString(): string
    {
        return self::class . '(' . json_encode($this->jsonSerialize(), JSON_UNESCAPED_SLASHES) . ')';
    }

    /**
     * What `var_dump()` and `print_r()` show: every field, the private key redacted.
     *
     * @return array<string, int|string|bool>
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * A rendering for logs, with the private key redacted. It cannot be read back: persist the key
     * elsewhere (a file or secret store) and pass it to the constructor.
     *
     * @return array<string, int|string|bool>
     */
    public function jsonSerialize(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'grpcCert' => $this->grpcCert,
            'grpcClientCert' => $this->grpcClientCert,
            'grpcClientKey' => $this->grpcClientKey !== '' ? self::REDACTED : '',
            'useSecureChannel' => $this->useSecureChannel,
        ];
    }

    /**
     * Both-or-neither, and no identity on a plaintext channel. The messages name the fields and
     * `host:port`, never a PEM.
     */
    private function assertClientIdentity(): void
    {
        $hasCert = $this->grpcClientCert !== '';
        $hasKey = $this->grpcClientKey !== '';
        if ($hasCert !== $hasKey) {
            throw new InvalidArgumentException(sprintf(
                'ClientConfig for %s has only %s set; set both grpcClientCert and grpcClientKey for mutual TLS, '
                . 'or neither.',
                $this->target(),
                $hasCert ? 'grpcClientCert' : 'grpcClientKey',
            ));
        }
        if ($hasCert && !$this->useSecureChannel) {
            throw new InvalidArgumentException(sprintf(
                'ClientConfig for %s has a client certificate but useSecureChannel is false; '
                . 'a client identity needs a TLS channel.',
                $this->target(),
            ));
        }
    }
}
