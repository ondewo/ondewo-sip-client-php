<?php

declare(strict_types=1);

namespace Ondewo\Sip\Auth;

use Closure;
use JsonException;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Acquires a Keycloak access token for the headless SDK and keeps it fresh, attaching it to every
 * gRPC call as `authorization: Bearer <access_token>`.
 *
 * Two start-ups, and neither ever falls back to the other:
 *
 * - **password** (`refreshToken` empty): ONE resource-owner password grant with
 *   `scope=offline_access` against the PUBLIC SDK client (no client secret), which returns a
 *   short-lived access token and a long-lived OFFLINE refresh token;
 * - **offline token** (`refreshToken` set): ONE refresh grant with the handed-off offline token.
 *   No password grant is sent, whatever `password` holds and whatever Keycloak answers.
 *
 * After that the access token is refreshed with the (possibly rotated) refresh token. PHP has no
 * long-lived threads in a request / FPM process, so the refresh is LAZY: every read (every gRPC
 * call, through `update_metadata`) refreshes a token that is within {@see EXPIRY_LEEWAY_S} of its
 * expiry. A failed refresh is backed off with full jitter ({@see backoffDelaySeconds()}) and is
 * raised only once the current token has actually EXPIRED: while it is still valid the call goes out
 * with it. While the backoff runs, no request is sent at all, so an outage of the token endpoint
 * never turns every gRPC call into a token request. A refused token is NEVER answered with a
 * password login: Keycloak's brute-force detection counts failed logins, and a realm restart
 * invalidates every offline session at once.
 *
 * Once `tokenExpirationInS` has elapsed since the initial acquisition the refresh stops and the
 * token is served as-is until it lapses.
 *
 * An instance IS the `update_metadata` callable of a generated `*Client` stub:
 *
 * ```php
 * $provider = new KeycloakTokenProvider('https://host/auth', 'ondewo-ccai-platform',
 *     'ondewo-nlu-cai-sdk-public', 'user@example.com', getenv('ONDEWO_PASSWORD'));
 * $client = new \Ondewo\Sip\SipClient('sip.example.com:443', [
 *     'credentials' => \Grpc\ChannelCredentials::createSsl(),
 *     'update_metadata' => $provider,
 * ]);
 * ```
 *
 * Normally it is built for you, once per credential set, by {@see ClientConfig::channelOptions()}.
 */
final class KeycloakTokenProvider
{
    /** Refresh this long before the access token's expiry. */
    public const EXPIRY_LEEWAY_S = 30.0;

    /**
     * Floor of any background wait. PHP refreshes lazily and has no background wait; the constant
     * is kept so that every ONDEWO SDK carries the same table.
     */
    public const MIN_REFRESH_DELAY_S = 1.0;

    /** First retry after a failed refresh, and the lower bound of every backoff delay. */
    public const RETRY_BASE_DELAY_S = 5.0;

    /** Ceiling of the backoff delay. */
    public const RETRY_MAX_DELAY_S = 300.0;

    /** `5 * 2**6 = 320 >= 300`: capping the exponent is what keeps a huge failure count finite. */
    public const MAX_RETRY_EXPONENT = 6;

    /** Bound of the whole token-endpoint exchange (connect + read); must stay below the leeway. */
    public const HTTP_TIMEOUT_S = 10.0;

    /** A quoted error body is truncated to this many characters. */
    public const MAX_ERROR_BODY_CHARS = 500;

    /**
     * Bounded wait for a background worker on stop. PHP has none; kept for the shared table.
     */
    public const THREAD_JOIN_TIMEOUT_S = 5.0;

    /** The token endpoint below `keycloakUrl`. */
    public const TOKEN_ENDPOINT_PATH = '/realms/%s/protocol/openid-connect/token';

    public readonly string $keycloakUrl;

    private string $password;

    private string $refreshToken;

    private string $accessToken = '';

    private float $accessTokenExpiresAt = 0.0;

    private ?float $loginDeadline = null;

    private int $consecutiveRefreshFailures = 0;

    private float $nextRefreshAttemptAt = 0.0;

    private bool $closed = false;

    private readonly string $tokenEndpoint;

    private readonly KeycloakTokenTransport $transport;

    private readonly Closure $timeFn;

    private readonly Closure $randomFn;

    /**
     * Acquire the initial access token (one password grant, or one refresh grant with
     * `$refreshToken`) and arm the `tokenExpirationInS` window.
     *
     * @param string                      $keycloakUrl        Keycloak base URL, the part before
     *                                                        `/realms/<realm>`; trailing `/` are
     *                                                        stripped
     * @param string                      $realm              the realm, e.g. `ondewo-ccai-platform`
     * @param string                      $clientId           the PUBLIC SDK client id (no secret is
     *                                                        ever sent)
     * @param string                      $username           technical user of the password grant
     * @param string                      $password           its password
     * @param int|null                    $tokenExpirationInS how long refreshing goes on, measured
     *                                                        from the initial acquisition; null =
     *                                                        until the offline session dies
     * @param bool                        $verifySsl          verify Keycloak's TLS certificate (the
     *                                                        default transport only)
     * @param KeycloakTokenTransport|null $transport          the HTTP exchange; default curl
     * @param callable|null               $timeFn             MONOTONIC clock in seconds; default
     *                                                        `hrtime()`
     * @param callable|null               $randomFn           source of a fraction in [0, 1] for the
     *                                                        backoff jitter, consulted on failures
     *                                                        only
     * @param LoggerInterface|null        $logger             receives the WARNING / INFO of a
     *                                                        failed / recovered refresh; default
     *                                                        `error_log()`
     * @param string                      $refreshToken       a handed-off Keycloak OFFLINE refresh
     *                                                        token used INSTEAD of the password.
     *                                                        Appended LAST so that an existing
     *                                                        positional call keeps its meaning.
     *
     * @throws KeycloakAuthenticationException if the initial acquisition fails (no retry, no fallback)
     */
    public function __construct(
        string $keycloakUrl,
        public readonly string $realm,
        public readonly string $clientId,
        public readonly string $username,
        #[\SensitiveParameter] string $password,
        public readonly ?int $tokenExpirationInS = null,
        public readonly bool $verifySsl = true,
        ?KeycloakTokenTransport $transport = null,
        ?callable $timeFn = null,
        ?callable $randomFn = null,
        private readonly ?LoggerInterface $logger = null,
        #[\SensitiveParameter] string $refreshToken = '',
    ) {
        $this->keycloakUrl = rtrim($keycloakUrl, '/');
        $this->password = $password;
        $this->refreshToken = $refreshToken;
        $this->transport = $transport ?? new CurlKeycloakTokenTransport($verifySsl);
        $this->timeFn = $timeFn !== null
            ? Closure::fromCallable($timeFn)
            : static fn (): float => hrtime(true) / 1e9;
        $this->randomFn = $randomFn !== null
            ? Closure::fromCallable($randomFn)
            : static fn (): float => random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
        $this->tokenEndpoint = $this->keycloakUrl . sprintf(self::TOKEN_ENDPOINT_PATH, rawurlencode($realm));

        $action = $refreshToken !== '' ? 'refresh' : 'login';
        try {
            if ($refreshToken !== '') {
                $this->refresh();
            } else {
                $this->login();
            }
        } catch (KeycloakAuthenticationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new KeycloakAuthenticationException(
                sprintf('Keycloak token %s failed (%s: %s)', $action, self::typeName($exception), $exception->getMessage()),
                0,
                $exception,
            );
        }
        if ($tokenExpirationInS !== null) {
            $this->loginDeadline = ($this->timeFn)() + $tokenExpirationInS;
        }
    }

    /**
     * The delay before the next attempt after `$consecutiveFailures` failed refreshes: uniform in
     * `[base, ceiling]`, the ceiling doubling from {@see RETRY_BASE_DELAY_S} up to
     * {@see RETRY_MAX_DELAY_S}. The jitter is the load-bearing half: N processes whose refreshes
     * fail in the same instant would otherwise retry in lockstep for the whole outage.
     *
     * @param int      $consecutiveFailures values below 1 count as 1; a huge value never overflows
     * @param callable $randomFn            source of a fraction; clamped to [0, 1] (NAN counts as 0)
     */
    public static function backoffDelaySeconds(int $consecutiveFailures, callable $randomFn): float
    {
        $exponent = min(max($consecutiveFailures, 1) - 1, self::MAX_RETRY_EXPONENT);
        $ceiling = min(self::RETRY_BASE_DELAY_S * (2 ** $exponent), self::RETRY_MAX_DELAY_S);
        $fraction = (float) $randomFn();
        $fraction = is_nan($fraction) ? 0.0 : min(max($fraction, 0.0), 1.0);

        return self::RETRY_BASE_DELAY_S + $fraction * ($ceiling - self::RETRY_BASE_DELAY_S);
    }

    /** The most recently issued access token. */
    public function accessToken(): string
    {
        return $this->accessToken;
    }

    /** When the current access token expires, on the provider's monotonic clock. */
    public function accessTokenExpiresAt(): float
    {
        return $this->accessTokenExpiresAt;
    }

    /** When refreshing stops (`tokenExpirationInS` after the initial acquisition), or null. */
    public function loginDeadline(): ?float
    {
        return $this->loginDeadline;
    }

    /** How many refreshes have failed in a row (0 when the last one succeeded). */
    public function consecutiveRefreshFailures(): int
    {
        return $this->consecutiveRefreshFailures;
    }

    /** No refresh is attempted before this time while failures are backing off (0 = none). */
    public function nextRefreshAttemptAt(): float
    {
        return $this->nextRefreshAttemptAt;
    }

    /**
     * The `authorization` metadata pair, refreshing the token first when it is within the leeway.
     *
     * @return array{0: string, 1: string} `['authorization', 'Bearer <access_token>']`
     *
     * @throws KeycloakAuthenticationException if the access token has expired and could not be refreshed
     */
    public function authorizationMetadata(): array
    {
        $this->refreshIfWithinWindow(($this->timeFn)());

        return [BearerTokenAuthenticator::HEADER, BearerTokenAuthenticator::SCHEME . ' ' . $this->accessToken];
    }

    /**
     * The `update_metadata` callback of `\Grpc\BaseStub`: sets (replaces) the `authorization` entry.
     *
     * @param array<string, list<string>> $metadata  the call metadata gRPC collected so far
     * @param string                      $jwtAudUri unused; part of the gRPC callback signature
     *
     * @return array<string, list<string>>
     *
     * @throws KeycloakAuthenticationException if the access token has expired and could not be refreshed
     */
    public function __invoke(array $metadata = [], string $jwtAudUri = ''): array
    {
        [$header, $value] = $this->authorizationMetadata();
        $metadata[$header] = [$value];

        return $metadata;
    }

    /**
     * Mark the provider closed: {@see KeycloakTokenProviderRegistry} then never hands it to a new
     * client. There is no background worker to stop in PHP, so the lazy path keeps working on
     * demand. Idempotent, and safe from any state, including a destructor.
     */
    public function stop(): void
    {
        $this->closed = true;
    }

    /** Alias of {@see stop()}. */
    public function close(): void
    {
        $this->stop();
    }

    /** Whether {@see stop()} / {@see close()} has been called. */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * What `var_dump()` and `print_r()` show: the non-secret state; the password, the refresh token
     * and the access token as {@see ClientConfig::REDACTED} when set, empty when not.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'keycloakUrl' => $this->keycloakUrl,
            'realm' => $this->realm,
            'clientId' => $this->clientId,
            'username' => $this->username,
            'password' => self::redact($this->password),
            'tokenExpirationInS' => $this->tokenExpirationInS,
            'verifySsl' => $this->verifySsl,
            'refreshToken' => self::redact($this->refreshToken),
            'accessToken' => self::redact($this->accessToken),
            'accessTokenExpiresAt' => $this->accessTokenExpiresAt,
            'loginDeadline' => $this->loginDeadline,
            'consecutiveRefreshFailures' => $this->consecutiveRefreshFailures,
            'nextRefreshAttemptAt' => $this->nextRefreshAttemptAt,
            'closed' => $this->closed,
        ];
    }

    /**
     * The decision table shared by every ONDEWO SDK: past the deadline nothing happens; a token
     * comfortably valid is kept; while backing off no request is sent (a valid token is served, an
     * expired one raises); otherwise one refresh grant, whose failure is raised only once the
     * token has expired. Never a password grant.
     *
     * @throws KeycloakAuthenticationException if the access token has expired and could not be refreshed
     */
    private function refreshIfWithinWindow(float $now): void
    {
        if ($this->loginDeadline !== null && $now >= $this->loginDeadline) {
            return;
        }
        if ($now < $this->accessTokenExpiresAt - self::EXPIRY_LEEWAY_S) {
            return;
        }
        if ($now < $this->nextRefreshAttemptAt) {
            if ($now < $this->accessTokenExpiresAt) {
                return;
            }
            throw new KeycloakAuthenticationException(sprintf(
                'The Keycloak access token has expired and the refresh is backing off after %d consecutive '
                . 'failure(s); the next attempt is due in %.1Fs',
                $this->consecutiveRefreshFailures,
                $this->nextRefreshAttemptAt - $now,
            ));
        }
        try {
            $this->refresh();
        } catch (Throwable $exception) {
            $this->recordRefreshFailure($exception);
            if (($this->timeFn)() < $this->accessTokenExpiresAt) {
                return;
            }
            if ($exception instanceof KeycloakAuthenticationException) {
                throw $exception;
            }
            throw new KeycloakAuthenticationException(
                sprintf('Keycloak token refresh failed (%s: %s)', self::typeName($exception), $exception->getMessage()),
                0,
                $exception,
            );
        }
        $this->recordRefreshSuccess();
    }

    /**
     * Count a failure, schedule the next attempt from NOW (the moment the failure is recorded, not
     * when the attempt started: an attempt that hung for the whole HTTP timeout would otherwise
     * schedule a retry that is already due) and log exactly one WARNING.
     */
    private function recordRefreshFailure(Throwable $exception): void
    {
        $now = ($this->timeFn)();
        ++$this->consecutiveRefreshFailures;
        $delay = self::backoffDelaySeconds($this->consecutiveRefreshFailures, $this->randomFn);
        $this->nextRefreshAttemptAt = $now + $delay;
        $remaining = $this->accessTokenExpiresAt - $now;
        $validity = $remaining > 0
            ? sprintf('the current access token stays valid for %.1Fs', $remaining)
            : 'the current access token has expired';
        $this->log('warning', sprintf(
            'Keycloak token refresh failed (attempt %d); retrying in %.1Fs, %s (%s: %s)',
            $this->consecutiveRefreshFailures,
            $delay,
            $validity,
            self::typeName($exception),
            $exception->getMessage(),
        ));
    }

    /** Reset the backoff and, after failures, log exactly one INFO. */
    private function recordRefreshSuccess(): void
    {
        if ($this->consecutiveRefreshFailures > 0) {
            $this->log('info', sprintf(
                'Keycloak token refresh recovered after %d failed attempt(s)',
                $this->consecutiveRefreshFailures,
            ));
        }
        $this->consecutiveRefreshFailures = 0;
        $this->nextRefreshAttemptAt = 0.0;
    }

    /** The one-time password grant with `scope=offline_access`. */
    private function login(): void
    {
        $this->storeTokens($this->postTokenRequest([
            'grant_type' => 'password',
            'client_id' => $this->clientId,
            'username' => $this->username,
            'password' => $this->password,
            'scope' => 'offline_access',
        ], 'login'));
    }

    /** The refresh grant with the current (offline) refresh token. */
    private function refresh(): void
    {
        $this->storeTokens($this->postTokenRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'refresh_token' => $this->refreshToken,
        ], 'refresh'));
    }

    /**
     * POST one grant and return the JSON object Keycloak answered.
     *
     * @param array<string, string> $form
     *
     * @return array<array-key, mixed>
     *
     * @throws KeycloakAuthenticationException if the endpoint is unreachable, answers a non-2xx
     *                                         status, a body that is not JSON or not a JSON object
     */
    private function postTokenRequest(array $form, string $action): array
    {
        try {
            $response = $this->transport->post($this->tokenEndpoint, $form, self::HTTP_TIMEOUT_S);
        } catch (KeycloakTransportException $exception) {
            throw new KeycloakAuthenticationException(
                sprintf(
                    'Keycloak token %s could not reach the token endpoint (%s: %s)',
                    $action,
                    self::typeName($exception),
                    $exception->getMessage(),
                ),
                0,
                $exception,
            );
        }
        $status = $response->statusCode;
        if ($status < 200 || $status >= 300) {
            throw new KeycloakAuthenticationException(sprintf(
                'Keycloak token %s failed with status %d: %s',
                $action,
                $status,
                mb_substr($response->body(), 0, self::MAX_ERROR_BODY_CHARS),
            ));
        }
        try {
            $body = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new KeycloakAuthenticationException(
                sprintf('Keycloak token %s returned status %d with a body that is not JSON', $action, $status),
                0,
                $exception,
            );
        }
        if (!$body instanceof stdClass) {
            throw new KeycloakAuthenticationException(sprintf(
                'Keycloak token %s returned a JSON %s instead of an object',
                $action,
                self::jsonTypeName($body),
            ));
        }

        return (array) json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Validate a token response completely, THEN assign: a malformed response changes no state.
     *
     * @param array<array-key, mixed> $payload
     *
     * @throws KeycloakAuthenticationException if there is no usable access_token or expires_in
     */
    private function storeTokens(array $payload): void
    {
        $accessToken = $payload['access_token'] ?? '';
        if (!is_string($accessToken) || $accessToken === '') {
            $names = array_map('strval', array_keys($payload));
            sort($names, SORT_STRING);
            throw new KeycloakAuthenticationException(sprintf(
                'Keycloak token response did not contain an access_token; the response carried the fields [%s] '
                . '(values withheld: a token response is credential material)',
                implode(', ', array_map(static fn (string $name): string => "'" . $name . "'", $names)),
            ));
        }
        $expiresIn = array_key_exists('expires_in', $payload) ? $payload['expires_in'] : 0;
        if (is_string($expiresIn) && is_numeric($expiresIn)) {
            $expiresIn = (float) $expiresIn;
        }
        if (!is_int($expiresIn) && !is_float($expiresIn)) {
            throw new KeycloakAuthenticationException(
                'Keycloak token response carried a non-numeric expires_in (value withheld)'
            );
        }
        $this->accessToken = $accessToken;
        $refreshToken = $payload['refresh_token'] ?? '';
        if (is_string($refreshToken) && $refreshToken !== '') {
            $this->refreshToken = $refreshToken;
        }
        $this->accessTokenExpiresAt = ($this->timeFn)() + max((float) $expiresIn, 0.0);
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message);

            return;
        }
        error_log(strtoupper($level) . ' | ' . $message);
    }

    private static function redact(string $secret): string
    {
        return $secret !== '' ? ClientConfig::REDACTED : '';
    }

    private static function typeName(Throwable $exception): string
    {
        $class = $exception::class;
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }

    private static function jsonTypeName(mixed $value): string
    {
        return match (true) {
            is_array($value) => 'array',
            is_string($value) => 'string',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            default => 'number',
        };
    }
}
