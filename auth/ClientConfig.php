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
 * With `keycloakUrl`, `realm` and `clientId` set, `channelOptions()` also attaches a shared
 * {@see KeycloakTokenProvider} as `update_metadata`, so every call carries
 * `authorization: Bearer <access_token>`. It authenticates with `password` (one password grant
 * with `scope=offline_access`) or, when `refreshToken` is set, with that handed-off Keycloak
 * OFFLINE token (one refresh grant, and never a password grant):
 *
 * ```php
 * $config = new \Ondewo\Sip\Auth\ClientConfig(
 *     host: 'sip.example.com',
 *     port: 443,
 *     keycloakUrl: 'https://keycloak.example.com/auth',
 *     realm: 'ondewo-ccai-platform',
 *     clientId: 'ondewo-nlu-cai-sdk-public',
 *     username: 'technical-user@example.com',
 *     refreshToken: getenv('ONDEWO_OFFLINE_TOKEN'), // or password: getenv('ONDEWO_PASSWORD')
 * );
 * $client = new \Ondewo\Sip\SipClient($config->target(), $config->channelOptions());
 * ```
 *
 * The client identity is checked BEFORE anything reaches ext-grpc: grpc-core aborts the whole
 * PHP process on a private key without its certificate, and silently drops a certificate without
 * its key. No message, `__toString()`, `var_dump()` / `print_r()` (`__debugInfo()`) or
 * `json_encode()` rendering of this object carries the private key, the password or the refresh
 * token.
 */
final class ClientConfig implements JsonSerializable, Stringable
{
    /** What every rendering shows instead of a non-empty `grpcClientKey`, `password` or `refreshToken`. */
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

    /** Keycloak base URL (the part before `/realms/<realm>`), trailing `/` stripped. */
    public readonly string $keycloakUrl;

    private string $password;

    private string $refreshToken;

    /**
     * @param string     $host             host name, IPv4 or IPv6 literal (bracketed by target()),
     *                                     or a gRPC target with a scheme (`dns:`, `unix:`, ...)
     * @param int|string $port             the server port
     * @param string     $grpcCert         PEM of the CA (or server certificate) to trust; empty
     *                                     trusts the platform's default roots
     * @param string     $grpcClientCert   PEM of the client certificate chain for mutual TLS
     * @param string     $grpcClientKey    PEM of the client certificate's private key
     * @param bool       $useSecureChannel false selects a plaintext channel (not for production)
     * @param string     $keycloakUrl      Keycloak base URL, e.g. `https://host/auth`; with `realm`
     *                                     and `clientId` all-or-nothing
     * @param string     $realm            Keycloak realm, e.g. `ondewo-ccai-platform`
     * @param string     $clientId         the PUBLIC SDK client id (no client secret exists)
     * @param string     $username         technical user of the password grant
     * @param string     $password         its password (or use `refreshToken`)
     * @param int|null   $tokenExpirationInS how long the token is refreshed, measured from the
     *                                     initial acquisition; null = until the offline session dies
     * @param bool       $verifySsl        verify Keycloak's TLS certificate on the token request only
     * @param string     $refreshToken     a handed-off Keycloak OFFLINE refresh token, used INSTEAD
     *                                     of the password (it wins when both are set). Appended
     *                                     LAST so that existing positional calls keep their meaning.
     *
     * @throws InvalidArgumentException if exactly one of grpcClientCert and grpcClientKey is set,
     *                                  a client identity is set on an insecure channel, only part of
     *                                  the Keycloak triple is set, or Keycloak is configured with
     *                                  neither a password nor a refresh token
     */
    public function __construct(
        public readonly string $host,
        public readonly int|string $port,
        public readonly string $grpcCert = '',
        public readonly string $grpcClientCert = '',
        #[\SensitiveParameter] string $grpcClientKey = '',
        public readonly bool $useSecureChannel = true,
        string $keycloakUrl = '',
        public readonly string $realm = '',
        public readonly string $clientId = '',
        public readonly string $username = '',
        #[\SensitiveParameter] string $password = '',
        public readonly ?int $tokenExpirationInS = null,
        public readonly bool $verifySsl = true,
        #[\SensitiveParameter] string $refreshToken = '',
    ) {
        $this->grpcClientKey = $grpcClientKey;
        $this->keycloakUrl = rtrim($keycloakUrl, '/');
        $this->password = $password;
        $this->refreshToken = $refreshToken;
        $this->assertClientIdentity();
        $this->assertKeycloakConfiguration();
    }

    /** The PEM private key of the client certificate (empty without mutual TLS). */
    public function grpcClientKey(): string
    {
        return $this->grpcClientKey;
    }

    /** The Keycloak password of the password grant (empty when unset). */
    public function password(): string
    {
        return $this->password;
    }

    /** The handed-off Keycloak offline refresh token (empty when unset). */
    public function refreshToken(): string
    {
        return $this->refreshToken;
    }

    /**
     * True when `keycloakUrl`, `realm` and `clientId` are all set: `channelOptions()` then attaches
     * a bearer token to every call. When false no `authorization` metadata is attached at all.
     */
    public function useKeycloak(): bool
    {
        return $this->keycloakUrl !== '' && $this->realm !== '' && $this->clientId !== '';
    }

    /**
     * The shared token provider of this credential set ({@see KeycloakTokenProviderRegistry}),
     * created with one token request on first use; null without a Keycloak configuration.
     *
     * @throws KeycloakAuthenticationException if the initial acquisition fails
     */
    public function keycloakTokenProvider(?LoggerInterface $logger = null): ?KeycloakTokenProvider
    {
        return $this->useKeycloak() ? KeycloakTokenProviderRegistry::providerFor($this, $logger) : null;
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
     * the `credentials` of this configuration. With a Keycloak configuration and no `update_metadata`
     * of the caller's own, the shared {@see KeycloakTokenProvider} is attached as `update_metadata`
     * (the initial token request happens HERE, so a refused credential fails now, not on a call).
     *
     * @param array<string, mixed> $options extra stub options and channel arguments
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException if `$options` carries its own `credentials`: TLS material
     *                                  goes through this configuration, so that it is checked
     * @throws KeycloakAuthenticationException if the initial Keycloak token request fails
     */
    public function channelOptions(array $options = [], ?LoggerInterface $logger = null): array
    {
        if (array_key_exists('credentials', $options)) {
            throw new InvalidArgumentException(
                'channelOptions() for ' . $this->target() . ' received a "credentials" option; '
                . 'set grpcCert, grpcClientCert, grpcClientKey and useSecureChannel on ClientConfig instead.'
            );
        }

        $credentials = $this->channelCredentials($logger);
        if ($this->useKeycloak() && !array_key_exists('update_metadata', $options)) {
            $options['update_metadata'] = $this->keycloakTokenProvider($logger);
        }

        return ['credentials' => $credentials] + $options + self::DEFAULT_CHANNEL_OPTIONS;
    }

    public function __toString(): string
    {
        return self::class . '(' . json_encode($this->jsonSerialize(), JSON_UNESCAPED_SLASHES) . ')';
    }

    /**
     * What `var_dump()` and `print_r()` show: every field, the private key, the password and the
     * refresh token redacted.
     *
     * @return array<string, int|string|bool|null>
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * A rendering for logs, with the private key, the password and the refresh token redacted (an
     * EMPTY one stays empty: "nobody set it" is usually what is being debugged). It cannot be read
     * back: persist the secrets elsewhere (a file or secret store) and pass them to the constructor.
     *
     * @return array<string, int|string|bool|null>
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
            'keycloakUrl' => $this->keycloakUrl,
            'realm' => $this->realm,
            'clientId' => $this->clientId,
            'username' => $this->username,
            'password' => $this->password !== '' ? self::REDACTED : '',
            'tokenExpirationInS' => $this->tokenExpirationInS,
            'verifySsl' => $this->verifySsl,
            'refreshToken' => $this->refreshToken !== '' ? self::REDACTED : '',
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

    /**
     * The Keycloak triple is all-or-nothing, and a Keycloak configuration needs a credential: a
     * password, a refresh token, or both (the refresh token then wins). The messages name fields,
     * never a value. A config without Keycloak is not checked further (no behaviour change).
     */
    private function assertKeycloakConfiguration(): void
    {
        $set = array_filter([$this->keycloakUrl, $this->realm, $this->clientId], static fn (string $v): bool => $v !== '');
        if ($set === []) {
            return;
        }
        if (count($set) !== 3) {
            throw new InvalidArgumentException(
                'The Keycloak fields `keycloakUrl`, `realm`, and `clientId` must be provided together in ClientConfig.'
            );
        }
        if ($this->password === '' && $this->refreshToken === '') {
            throw new InvalidArgumentException(
                'Either the field `password` or the field `refreshToken` (`refresh_token`) is mandatory in ClientConfig.'
            );
        }
    }
}
