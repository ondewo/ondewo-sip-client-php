<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Auth;

use Ondewo\Sip\Auth\BearerTokenAuthenticator;
use Ondewo\Sip\Auth\ClientConfig;
use Ondewo\Sip\Auth\KeycloakAuthenticationException;
use Ondewo\Sip\Auth\KeycloakTokenProvider;
use Ondewo\Sip\Auth\KeycloakTokenResponse;
use Ondewo\Sip\Auth\KeycloakTransportException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * The shared test catalogue of the ONDEWO client SDKs' Keycloak token handling (T01-T47), with a
 * scripted token endpoint and a fake monotonic clock: no network and no real sleeps.
 *
 * PHP refreshes LAZILY (there is no background worker in a request / FPM process), so the cases
 * that the threaded SDKs run against their background loop (T12-T18, T25, T41 and the loop halves
 * of T46/T47) are run here as their lazy equivalents: the same decision table, the same backoff
 * ladder, the same "measured from the moment the failure is recorded", driven by gRPC-call reads.
 * T26 (16 concurrent callers) is NOT APPLICABLE: PHP has no threads, so single-flight is trivially
 * true; testT26... asserts its sequential half (one request for 320 reads during an outage).
 * T40 (a join that times out during stop) is not applicable either: stop() joins nothing.
 *
 * Default script: clock 1000, first token `acc-1` with `expires_in` 300 (expires at 1300, inside
 * the leeway from 1270), jitter fraction 1.0 (the backoff is its ceiling).
 */
#[CoversClass(KeycloakTokenProvider::class)]
final class KeycloakTokenProviderTest extends TestCase
{
    private FakeClock $clock;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->logger = new RecordingLogger();
    }

    // ---------------------------------------------------------------- A. backoff ladder

    public function testT01TheCeilingDoublesUpToTheMaximum(): void
    {
        $ladder = array_map(
            static fn (int $n): float => KeycloakTokenProvider::backoffDelaySeconds($n, static fn (): float => 1.0),
            range(1, 10),
        );

        self::assertSame([5.0, 10.0, 20.0, 40.0, 80.0, 160.0, 300.0, 300.0, 300.0, 300.0], $ladder);
    }

    public function testT02AZeroFractionIsAlwaysTheBase(): void
    {
        foreach (range(1, 19) as $n) {
            self::assertSame(5.0, KeycloakTokenProvider::backoffDelaySeconds($n, static fn (): float => 0.0));
        }
    }

    public function testT03TheDelayIsInsideTheWindow(): void
    {
        self::assertSame(7.5, KeycloakTokenProvider::backoffDelaySeconds(2, static fn (): float => 0.5));
        self::assertSame(8.75, KeycloakTokenProvider::backoffDelaySeconds(3, static fn (): float => 0.25));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function faultyFractions(): iterable
    {
        yield '-1' => [-1.0];
        yield '-0.001' => [-0.001];
        yield '1.5' => [1.5];
        yield '1e9' => [1e9];
        yield 'NAN' => [NAN];
        yield 'INF' => [INF];
        yield '-INF' => [-INF];
    }

    #[DataProvider('faultyFractions')]
    public function testT04AFaultyJitterSourceIsClamped(float $fraction): void
    {
        $delay = KeycloakTokenProvider::backoffDelaySeconds(4, static fn (): float => $fraction);

        self::assertGreaterThanOrEqual(5.0, $delay);
        self::assertLessThanOrEqual(40.0, $delay);
    }

    public function testT05AFailureCountBelowOneCountsAsOne(): void
    {
        self::assertSame(5.0, KeycloakTokenProvider::backoffDelaySeconds(0, static fn (): float => 1.0));
        self::assertSame(5.0, KeycloakTokenProvider::backoffDelaySeconds(-3, static fn (): float => 1.0));
        self::assertSame(5.0, KeycloakTokenProvider::backoffDelaySeconds(PHP_INT_MIN, static fn (): float => 1.0));
    }

    public function testT06AHugeFailureCountNeverOverflows(): void
    {
        self::assertSame(300.0, KeycloakTokenProvider::backoffDelaySeconds(1_000_000_000, static fn (): float => 1.0));
        self::assertSame(300.0, KeycloakTokenProvider::backoffDelaySeconds(PHP_INT_MAX, static fn (): float => 1.0));
    }

    public function testT07TheConstantsAreTheSharedTableAndConsistent(): void
    {
        self::assertSame(30.0, KeycloakTokenProvider::EXPIRY_LEEWAY_S);
        self::assertSame(1.0, KeycloakTokenProvider::MIN_REFRESH_DELAY_S);
        self::assertSame(5.0, KeycloakTokenProvider::RETRY_BASE_DELAY_S);
        self::assertSame(300.0, KeycloakTokenProvider::RETRY_MAX_DELAY_S);
        self::assertSame(6, KeycloakTokenProvider::MAX_RETRY_EXPONENT);
        self::assertSame(10.0, KeycloakTokenProvider::HTTP_TIMEOUT_S);
        self::assertSame(500, KeycloakTokenProvider::MAX_ERROR_BODY_CHARS);
        self::assertSame(5.0, KeycloakTokenProvider::THREAD_JOIN_TIMEOUT_S);

        self::assertGreaterThan(KeycloakTokenProvider::MIN_REFRESH_DELAY_S, KeycloakTokenProvider::RETRY_BASE_DELAY_S);
        self::assertGreaterThanOrEqual(
            KeycloakTokenProvider::RETRY_MAX_DELAY_S,
            KeycloakTokenProvider::RETRY_BASE_DELAY_S * 2 ** KeycloakTokenProvider::MAX_RETRY_EXPONENT,
        );
        self::assertLessThan(KeycloakTokenProvider::EXPIRY_LEEWAY_S, KeycloakTokenProvider::HTTP_TIMEOUT_S);
    }

    // ---------------------------------------------------------------- B. a failure never ends the refreshing

    public function testT08ARefusedRefreshIsRetriedAndRecovers(): void
    {
        $transport = $this->transport([$this->tok('acc-1'), FakeKeycloakTransport::refused(), $this->tok('acc-3')]);
        $provider = $this->provider($transport);

        $this->clock->advance(280);
        self::assertSame(['authorization', 'Bearer acc-1'], $provider->authorizationMetadata());
        $this->clock->advance(5);
        self::assertSame(['authorization', 'Bearer acc-3'], $provider->authorizationMetadata());

        self::assertSame(['password', 'refresh_token', 'refresh_token'], $transport->grants());
        self::assertSame('acc-3', $provider->accessToken());
        self::assertSame(0, $provider->consecutiveRefreshFailures());
    }

    /**
     * @return iterable<string, array{KeycloakTokenResponse|Throwable}>
     */
    public static function failureKinds(): iterable
    {
        yield 'network error' => [new KeycloakTransportException('curl error 6: Could not resolve host: kc')];
        yield 'connection refused' => [new KeycloakTransportException('curl error 7: Connection refused')];
        yield 'timeout' => [new KeycloakTransportException('curl error 28: Operation timed out')];
        yield 'a non-auth error of the transport (bug)' => [new RuntimeException('bug in the transport')];
        yield 'a PHP Error of the transport (bug)' => [new TypeError('wrong argument')];
        yield 'HTTP 503 with a JSON body' => [FakeKeycloakTransport::unavailable()];
        yield 'HTTP 502 with an empty body' => [new KeycloakTokenResponse(502, '')];
        yield '200 not JSON' => [new KeycloakTokenResponse(200, '<html>bad gateway</html>')];
        yield '200 a JSON array' => [new KeycloakTokenResponse(200, '["acc-x"]')];
        yield '200 without access_token' => [FakeKeycloakTransport::json(200, ['expires_in' => 300])];
        yield '200 with expires_in "soon"' => [FakeKeycloakTransport::json(200, ['access_token' => 'x', 'expires_in' => 'soon'])];
    }

    #[DataProvider('failureKinds')]
    public function testT09NoFailureKindEndsTheRefreshing(KeycloakTokenResponse|Throwable $failure): void
    {
        $transport = $this->transport([$this->tok('acc-1'), $failure, $this->tok('acc-ok')]);
        $provider = $this->provider($transport);

        $this->clock->advance(280);
        self::assertSame('Bearer acc-1', $provider->authorizationMetadata()[1]);
        self::assertSame(1, $provider->consecutiveRefreshFailures());
        $this->clock->advance(5);
        self::assertSame('Bearer acc-ok', $provider->authorizationMetadata()[1]);

        self::assertCount(3, $transport->requests);
        self::assertSame(0, $provider->consecutiveRefreshFailures());
    }

    public function testT10APersistentFailureKeepsBeingRetried(): void
    {
        $transport = $this->transport([$this->tok('acc-1'), FakeKeycloakTransport::unavailable(), null]);
        $provider = $this->provider($transport);

        $this->clock->advance(270);
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $this->clock->now = max($this->clock->now, $provider->nextRefreshAttemptAt());
            $this->readIgnoringExpiry($provider);
        }

        self::assertSame(array_merge(['password'], array_fill(0, 6, 'refresh_token')), $transport->grants());
        self::assertSame('acc-1', $provider->accessToken());
        self::assertSame(6, $provider->consecutiveRefreshFailures());
    }

    public function testT11ARefusedClientIsNeverAnsweredWithAPasswordLogin(): void
    {
        $transport = $this->transport([
            $this->tok('acc-1'),
            FakeKeycloakTransport::json(401, ['error' => 'invalid_client']),
            null,
        ]);
        $provider = $this->provider($transport);

        $this->clock->advance(270);
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $this->clock->now = max($this->clock->now, $provider->nextRefreshAttemptAt());
            $this->readIgnoringExpiry($provider);
        }

        self::assertSame(['password'], array_values(array_filter($transport->grants(), static fn (string $g): bool => $g === 'password')));
        self::assertCount(11, $transport->requests);
    }

    // ---------------------------------------------------------------- C. backoff (lazy equivalents)

    public function testT12NoRequestBeforeTheLeewayAndTheFirstRetryIsTheBaseDelay(): void
    {
        $transport = $this->transport([$this->tok('acc-1'), FakeKeycloakTransport::unavailable(), $this->tok('acc-2')]);
        $provider = $this->provider($transport);

        $this->clock->now = 1269.9;
        $provider->authorizationMetadata();
        self::assertCount(1, $transport->requests, 'no request while the token is comfortably valid');

        $this->clock->now = 1270.0;
        $provider->authorizationMetadata();
        self::assertCount(2, $transport->requests, 'the refresh starts exactly 30 s before the expiry');
        self::assertSame(1275.0, $provider->nextRefreshAttemptAt());

        $this->clock->now = 1274.9;
        $provider->authorizationMetadata();
        self::assertCount(2, $transport->requests, 'the backoff is the 5 s base, not the 1 s floor');

        $this->clock->now = 1275.0;
        self::assertSame('Bearer acc-2', $provider->authorizationMetadata()[1]);
        self::assertCount(3, $transport->requests);
    }

    public function testT13APersistentOutageBacksOffAlongTheLadder(): void
    {
        $transport = $this->transport([$this->tok('acc-1'), FakeKeycloakTransport::unavailable(), null]);
        $provider = $this->provider($transport);

        $this->clock->now = 1270.0;
        $gaps = [];
        for ($attempt = 0; $attempt < 9; ++$attempt) {
            $this->readIgnoringExpiry($provider);
            $gaps[] = $provider->nextRefreshAttemptAt() - $this->clock->now;
            $this->clock->now = $provider->nextRefreshAttemptAt();
        }

        self::assertSame([5.0, 10.0, 20.0, 40.0, 80.0, 160.0, 300.0, 300.0, 300.0], $gaps);
        self::assertCount(10, $transport->requests);
    }

    public function testT14ASuccessResetsTheBackoffAndLogsOneRecovery(): void
    {
        $transport = $this->transport([
            $this->tok('acc-1'),
            FakeKeycloakTransport::unavailable(),
            FakeKeycloakTransport::unavailable(),
            $this->tok('acc-4', 120),
            FakeKeycloakTransport::unavailable(),
        ]);
        $provider = $this->provider($transport);

        $this->clock->now = 1270.0;
        $provider->authorizationMetadata();
        self::assertSame(1275.0, $provider->nextRefreshAttemptAt());
        $this->clock->now = 1275.0;
        $provider->authorizationMetadata();
        self::assertSame(1285.0, $provider->nextRefreshAttemptAt());
        $this->clock->now = 1285.0;
        self::assertSame('Bearer acc-4', $provider->authorizationMetadata()[1]);
        self::assertSame(0.0, $provider->nextRefreshAttemptAt());
        self::assertSame(1405.0, $provider->accessTokenExpiresAt());

        $this->clock->now = 1374.9;
        $provider->authorizationMetadata();
        self::assertCount(4, $transport->requests, 'the healthy schedule is back: 90 s until the leeway');
        $this->clock->now = 1375.0;
        $provider->authorizationMetadata();
        self::assertSame(1380.0, $provider->nextRefreshAttemptAt(), 'the ladder starts over at the base');

        self::assertSame(['Keycloak token refresh recovered after 2 failed attempt(s)'], $this->logger->messages('info'));
        self::assertCount(3, $this->logger->messages('warning'));
    }

    public function testT15TheBackoffIsMeasuredFromWhenTheFailureIsRecorded(): void
    {
        $clock = $this->clock;
        $transport = new FakeKeycloakTransport(
            [$this->tok('acc-1'), FakeKeycloakTransport::unavailable()],
            static function () use ($clock): void {
                $clock->advance(KeycloakTokenProvider::HTTP_TIMEOUT_S);
            },
        );
        $provider = $this->provider($transport);
        self::assertSame(1310.0, $provider->accessTokenExpiresAt(), 'the expiry is read when the response is stored');

        $this->clock->now = 1280.0;
        self::assertSame('Bearer acc-1', $provider->authorizationMetadata()[1]);

        self::assertSame(1290.0, $this->clock->now);
        self::assertSame(1295.0, $provider->nextRefreshAttemptAt(), 'record time + 5, not attempt start + 5');
    }

    public function testT16TheDeadlineEndsTheRefreshingEvenDuringABackoff(): void
    {
        $clock = $this->clock;
        $sentAt = [];
        $transport = new FakeKeycloakTransport(
            [$this->tok('acc-1', 30), FakeKeycloakTransport::unavailable(), null],
            static function () use ($clock, &$sentAt): void {
                $sentAt[] = $clock->now;
            },
        );
        $provider = $this->provider($transport, tokenExpirationInS: 100);
        self::assertSame(1100.0, $provider->loginDeadline());

        while ($this->clock->now < 1100.0) {
            $this->readIgnoringExpiry($provider);
            $this->clock->now = min(max($provider->nextRefreshAttemptAt(), $this->clock->now + 1), 1100.0);
        }
        $requests = count($transport->requests);
        foreach ([1100.0, 1101.0, 5000.0] as $now) {
            $this->clock->now = $now;
            self::assertSame('Bearer acc-1', $provider->authorizationMetadata()[1], 'served as-is, never raised');
        }

        self::assertGreaterThan(2, $requests, 'the outage was retried inside the window');
        self::assertCount($requests, $transport->requests, 'no request after the deadline');
        self::assertLessThan(1100.0, max($sentAt));
    }

    public function testT17StopMarksTheProviderClosedAndTheLazyPathStillWorks(): void
    {
        $transport = $this->transport([$this->tok('acc-1'), FakeKeycloakTransport::unavailable(), $this->tok('acc-2')]);
        $provider = $this->provider($transport);
        $this->clock->now = 1270.0;
        $provider->authorizationMetadata();

        $provider->stop();

        self::assertTrue($provider->isClosed());
        self::assertSame(['password', 'refresh_token'], $transport->grants(), 'stop() sends nothing');
        $this->clock->now = 1275.0;
        self::assertSame('Bearer acc-2', $provider->authorizationMetadata()[1]);
    }

    // ---------------------------------------------------------------- D. the lazy path shares the backoff

    public function testT19AFailedRefreshInsideTheLeewayServesTheStillValidToken(): void
    {
        $provider = $this->provider($this->transport([$this->tok('acc-1'), FakeKeycloakTransport::unavailable()]));

        $this->clock->advance(271);

        self::assertSame(['authorization', 'Bearer acc-1'], $provider->authorizationMetadata());
        self::assertSame(
            ['Keycloak token refresh failed (attempt 1); retrying in 5.0s, the current access token stays valid for '
            . '29.0s (KeycloakAuthenticationException: Keycloak token refresh failed with status 503: '
            . '{"error":"temporarily_unavailable"})'],
            $this->logger->messages('warning'),
        );
    }

    public function testT20NoRequestWhileBackingOff(): void
    {
        $transport = $this->transport([$this->tok('acc-1'), FakeKeycloakTransport::unavailable(), null]);
        $provider = $this->provider($transport);

        $this->clock->advance(275);
        for ($call = 0; $call < 100; ++$call) {
            self::assertSame('Bearer acc-1', $provider->authorizationMetadata()[1]);
            $this->clock->advance(0.04);
        }

        self::assertSame(['password', 'refresh_token'], $transport->grants());
    }

    public function testT21AnExpiredTokenWhileBackingOffRaisesWithoutARequest(): void
    {
        $transport = $this->transport([$this->tok('acc-1'), FakeKeycloakTransport::unavailable(), null]);
        $provider = $this->provider($transport);

        $this->clock->advance(299);
        self::assertSame('Bearer acc-1', $provider->authorizationMetadata()[1]);
        $this->clock->advance(2);

        try {
            $provider->authorizationMetadata();
            self::fail('an expired token was served');
        } catch (KeycloakAuthenticationException $exception) {
            self::assertSame(
                'The Keycloak access token has expired and the refresh is backing off after 1 consecutive failure(s); '
                . 'the next attempt is due in 3.0s',
                $exception->getMessage(),
            );
        }
        self::assertCount(2, $transport->requests);
    }

    public function testT22AnExpiredTokenWhoseRefreshFailsRaisesTheTypedError(): void
    {
        $provider = $this->provider($this->transport([$this->tok('acc-1'), FakeKeycloakTransport::refused()]));
        $this->clock->advance(400);

        $this->expectException(KeycloakAuthenticationException::class);
        $this->expectExceptionMessage(
            'Keycloak token refresh failed with status 400: '
            . '{"error":"invalid_grant","error_description":"Offline user session not found"}'
        );
        $provider->authorizationMetadata();
    }

    public function testT23ANonAuthTransportErrorOnAnExpiredTokenIsWrappedWithItsCause(): void
    {
        $bug = new RuntimeException('boom');
        $provider = $this->provider($this->transport([$this->tok('acc-1'), $bug]));
        $this->clock->advance(400);

        try {
            $provider->authorizationMetadata();
            self::fail('no error');
        } catch (KeycloakAuthenticationException $exception) {
            self::assertSame('Keycloak token refresh failed (RuntimeException: boom)', $exception->getMessage());
            self::assertSame($bug, $exception->getPrevious());
        }
    }

    public function testT24TheLazyPathRecoversAndLogsOneInfo(): void
    {
        $provider = $this->provider($this->transport([$this->tok('acc-1'), FakeKeycloakTransport::unavailable(), $this->tok('acc-3')]));

        $this->clock->advance(280);
        $provider->authorizationMetadata();
        $this->clock->advance(5);

        self::assertSame('Bearer acc-3', $provider->authorizationMetadata()[1]);
        self::assertSame(0, $provider->consecutiveRefreshFailures());
        self::assertSame(['Keycloak token refresh recovered after 1 failed attempt(s)'], $this->logger->messages('info'));
    }

    public function testT25ALazyFailureSchedulesTheNextAttempt(): void
    {
        $transport = $this->transport([$this->tok('acc-1'), FakeKeycloakTransport::unavailable(), $this->tok('acc-3')]);
        $provider = $this->provider($transport);

        $this->clock->advance(280);
        $provider->authorizationMetadata();
        $this->clock->advance(2);
        $provider->authorizationMetadata();

        self::assertEqualsWithDelta(3.0, $provider->nextRefreshAttemptAt() - $this->clock->now, 1e-9);
        self::assertCount(2, $transport->requests);
        $this->clock->advance(3);
        self::assertSame('Bearer acc-3', $provider->authorizationMetadata()[1]);
    }

    /**
     * T26 proper (16 concurrent callers) is not applicable: PHP has no threads, so two reads can
     * never overlap. Its observable half is asserted: 16 x 20 reads during an outage cause ONE request
     * and every one of them carries the still-valid token.
     */
    public function testT26ManyReadsDuringAnOutageSendOneRequest(): void
    {
        $transport = $this->transport([$this->tok('acc-1', 20), new KeycloakTransportException('curl error 7: refused'), null]);
        $provider = $this->provider($transport);

        $values = [];
        for ($caller = 0; $caller < 16; ++$caller) {
            for ($call = 0; $call < 20; ++$call) {
                $values[] = $provider->authorizationMetadata()[1];
                $this->clock->advance(0.001);
            }
        }

        self::assertSame(array_fill(0, 320, 'Bearer acc-1'), $values);
        self::assertSame(['password', 'refresh_token'], $transport->grants());
    }

    // ---------------------------------------------------------------- E. response validation

    /**
     * @return iterable<string, array{KeycloakTransportException}>
     */
    public static function networkFailures(): iterable
    {
        yield 'network' => [new KeycloakTransportException('curl error 6: Could not resolve host: kc')];
        yield 'connection refused' => [new KeycloakTransportException('curl error 7: Connection refused')];
        yield 'timeout' => [new KeycloakTransportException('curl error 28: Operation timed out')];
    }

    #[DataProvider('networkFailures')]
    public function testT27ANetworkFailureAtConstructionIsTheTypedErrorWithItsCause(KeycloakTransportException $failure): void
    {
        try {
            $this->provider($this->transport([$failure]));
            self::fail('no error');
        } catch (KeycloakAuthenticationException $exception) {
            self::assertSame(
                'Keycloak token login could not reach the token endpoint (KeycloakTransportException: '
                . $failure->getMessage() . ')',
                $exception->getMessage(),
            );
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testAnyOtherErrorAtConstructionIsWrappedWithItsCause(): void
    {
        $bug = new TypeError('bad transport');
        try {
            $this->provider($this->transport([$bug]));
            self::fail('no error');
        } catch (KeycloakAuthenticationException $exception) {
            self::assertSame('Keycloak token login failed (TypeError: bad transport)', $exception->getMessage());
            self::assertSame($bug, $exception->getPrevious());
        }
    }

    public function testT28ABodyThatIsNotJsonAtConstruction(): void
    {
        try {
            $this->provider($this->transport([new KeycloakTokenResponse(200, '<html>bad gateway</html>')]));
            self::fail('no error');
        } catch (KeycloakAuthenticationException $exception) {
            self::assertSame('Keycloak token login returned status 200 with a body that is not JSON', $exception->getMessage());
            self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonObjectJson(): iterable
    {
        yield 'array' => ['["acc-1"]', 'array'];
        yield 'empty array' => ['[]', 'array'];
        yield 'string' => ['"acc-1"', 'string'];
        yield 'number' => ['42', 'number'];
        yield 'float' => ['4.2', 'number'];
        yield 'boolean' => ['true', 'boolean'];
        yield 'null' => ['null', 'null'];
    }

    #[DataProvider('nonObjectJson')]
    public function testT29AJsonBodyThatIsNotAnObjectAtConstruction(string $body, string $type): void
    {
        $this->expectException(KeycloakAuthenticationException::class);
        $this->expectExceptionMessage("Keycloak token login returned a JSON $type instead of an object");
        $this->provider($this->transport([new KeycloakTokenResponse(200, $body)]));
    }

    public function testT30AHugeErrorBodyIsTruncated(): void
    {
        try {
            $this->provider($this->transport([new KeycloakTokenResponse(502, str_repeat('<p>bad gateway</p>', 600))]));
            self::fail('no error');
        } catch (KeycloakAuthenticationException $exception) {
            self::assertLessThan(500 + 200, strlen($exception->getMessage()));
            self::assertStringStartsWith('Keycloak token login failed with status 502: <p>bad gateway</p>', $exception->getMessage());
        }
    }

    public function testATruncatedErrorBodyCountsCharactersNotBytes(): void
    {
        try {
            $this->provider($this->transport([new KeycloakTokenResponse(500, str_repeat('ä', 1000))]));
            self::fail('no error');
        } catch (KeycloakAuthenticationException $exception) {
            self::assertSame('Keycloak token login failed with status 500: ' . str_repeat('ä', 500), $exception->getMessage());
        }
    }

    public function testT31AResponseWithoutAccessTokenNamesTheFieldsAndNoValue(): void
    {
        try {
            $this->provider($this->transport([
                FakeKeycloakTransport::json(200, ['refresh_token' => 'secret-offline-token-77', 'expires_in' => 300]),
            ]));
            self::fail('no error');
        } catch (KeycloakAuthenticationException $exception) {
            self::assertSame(
                "Keycloak token response did not contain an access_token; the response carried the fields "
                . "['expires_in', 'refresh_token'] (values withheld: a token response is credential material)",
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('secret-offline-token-77', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableAccessTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'number' => [12345];
        yield 'list' => [['acc-1']];
    }

    #[DataProvider('unusableAccessTokens')]
    public function testAnUnusableAccessTokenIsRefused(mixed $accessToken): void
    {
        $this->expectException(KeycloakAuthenticationException::class);
        $this->expectExceptionMessage("the response carried the fields ['access_token', 'expires_in']");
        $this->provider($this->transport([FakeKeycloakTransport::json(200, ['access_token' => $accessToken, 'expires_in' => 300])]));
    }

    public function testAnEmptyJsonObjectNamesNoFields(): void
    {
        $this->expectExceptionMessage('the response carried the fields [] (values withheld');
        $this->provider($this->transport([new KeycloakTokenResponse(200, '{}')]));
    }

    public function testT32ANonNumericExpiresInOnRefreshChangesNoState(): void
    {
        $provider = $this->provider($this->transport([
            $this->tok('acc-1'),
            FakeKeycloakTransport::json(200, ['access_token' => 'acc-2', 'expires_in' => 'soon', 'refresh_token' => 'off-2']),
            $this->tok('acc-3', 300, null),
        ]));
        $this->clock->advance(280);

        self::assertSame('Bearer acc-1', $provider->authorizationMetadata()[1]);
        self::assertSame('acc-1', $provider->accessToken());
        self::assertSame(1300.0, $provider->accessTokenExpiresAt());
        self::assertStringContainsString(
            'Keycloak token response carried a non-numeric expires_in (value withheld)',
            $this->logger->messages('warning')[0],
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonNumericExpiresIn(): iterable
    {
        yield 'word' => ['soon'];
        yield 'null' => [null];
        yield 'boolean' => [true];
        yield 'list' => [[300]];
        yield 'object' => [['seconds' => 300]];
        yield 'empty string' => [''];
    }

    #[DataProvider('nonNumericExpiresIn')]
    public function testANonNumericExpiresInIsRefusedWithoutItsValue(mixed $expiresIn): void
    {
        $this->expectException(KeycloakAuthenticationException::class);
        $this->expectExceptionMessage('Keycloak token response carried a non-numeric expires_in (value withheld)');
        $this->provider($this->transport([FakeKeycloakTransport::json(200, ['access_token' => 'acc-1', 'expires_in' => $expiresIn])]));
    }

    /**
     * @return iterable<string, array{mixed, float}>
     */
    public static function numericExpiresIn(): iterable
    {
        yield 'T33 numeric string' => ['300', 1300.0];
        yield 'integer' => [300, 1300.0];
        yield 'float' => [0.5, 1000.5];
        yield 'float string' => ['12.5', 1012.5];
        yield 'T34 negative' => [-50, 1000.0];
        yield 'zero' => [0, 1000.0];
    }

    #[DataProvider('numericExpiresIn')]
    public function testT33T34ANumericExpiresInSetsTheExpiry(mixed $expiresIn, float $expiresAt): void
    {
        $provider = $this->provider($this->transport([
            FakeKeycloakTransport::json(200, ['access_token' => 'acc-1', 'expires_in' => $expiresIn]),
        ]));

        self::assertSame($expiresAt, $provider->accessTokenExpiresAt());
    }

    public function testAMissingExpiresInCountsAsZero(): void
    {
        $provider = $this->provider($this->transport([FakeKeycloakTransport::json(200, ['access_token' => 'acc-1'])]));

        self::assertSame(1000.0, $provider->accessTokenExpiresAt());
    }

    public function testT35ARefreshWithoutANewRefreshTokenKeepsThePreviousOne(): void
    {
        $transport = $this->transport([
            $this->tok('acc-1', 300, 'off-1'),
            $this->tok('acc-2', 300, null),
            $this->tok('acc-3', 300, null),
        ]);
        $provider = $this->provider($transport);

        $this->clock->now = 1270.0;
        self::assertSame('Bearer acc-2', $provider->authorizationMetadata()[1]);
        $this->clock->now = 1540.0;
        self::assertSame('Bearer acc-3', $provider->authorizationMetadata()[1]);

        self::assertSame(['off-1', 'off-1'], [$transport->requests[1]['form']['refresh_token'], $transport->requests[2]['form']['refresh_token']]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableRefreshTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'number' => [7];
    }

    #[DataProvider('unusableRefreshTokens')]
    public function testAnUnusableRotatedRefreshTokenKeepsThePreviousOne(mixed $refreshToken): void
    {
        $transport = $this->transport([
            $this->tok('acc-1', 300, 'off-1'),
            FakeKeycloakTransport::json(200, ['access_token' => 'acc-2', 'expires_in' => 300, 'refresh_token' => $refreshToken]),
            $this->tok('acc-3', 300, null),
        ]);
        $provider = $this->provider($transport);

        $this->clock->now = 1270.0;
        $provider->authorizationMetadata();
        $this->clock->now = 1540.0;
        $provider->authorizationMetadata();

        self::assertSame('off-1', $transport->requests[2]['form']['refresh_token']);
    }

    public function testT36TheGrantFormsUrlAndTimeoutAreExact(): void
    {
        $transport = $this->transport([$this->tok('acc-1'), $this->tok('acc-2')]);
        $provider = new KeycloakTokenProvider(
            'https://kc.example.com/auth///',
            'ondewo-ccai-platform',
            'ondewo-nlu-cai-sdk-public',
            'user@example.com',
            'pw',
            transport: $transport,
            timeFn: $this->clock,
            randomFn: static fn (): float => 1.0,
            logger: $this->logger,
        );
        $this->clock->now = 1270.0;
        $provider->authorizationMetadata();

        $url = 'https://kc.example.com/auth/realms/ondewo-ccai-platform/protocol/openid-connect/token';
        self::assertSame('https://kc.example.com/auth', $provider->keycloakUrl);
        self::assertSame([
            'url' => $url,
            'form' => [
                'grant_type' => 'password',
                'client_id' => 'ondewo-nlu-cai-sdk-public',
                'username' => 'user@example.com',
                'password' => 'pw',
                'scope' => 'offline_access',
            ],
            'timeout' => 10.0,
        ], $transport->requests[0]);
        self::assertSame([
            'url' => $url,
            'form' => ['grant_type' => 'refresh_token', 'client_id' => 'ondewo-nlu-cai-sdk-public', 'refresh_token' => 'off-1'],
            'timeout' => 10.0,
        ], $transport->requests[1]);
    }

    public function testTheRealmIsUrlEncodedInTheTokenEndpoint(): void
    {
        $transport = $this->transport([$this->tok('acc-1')]);
        new KeycloakTokenProvider('https://kc', 'my realm/x', 'c', 'u', 'p', transport: $transport, timeFn: $this->clock);

        self::assertSame('https://kc/realms/my%20realm%2Fx/protocol/openid-connect/token', $transport->requests[0]['url']);
    }

    // ---------------------------------------------------------------- F. no credential in logs / errors

    public function testT37NoCredentialReachesALogOrAnError(): void
    {
        $secrets = ['pw-never-logged-5d1c', 'acc-secret-1', 'off-secret-1', 'acc-secret-2'];
        $transport = $this->transport([
            $this->tok('acc-secret-1', 300, 'off-secret-1'),
            FakeKeycloakTransport::refused(),
            $this->tok('acc-secret-2', 300, null),
        ]);
        $provider = $this->provider($transport, password: 'pw-never-logged-5d1c');
        $this->clock->advance(280);
        $provider->authorizationMetadata();
        $this->clock->advance(5);
        $provider->authorizationMetadata();

        $log = $this->logger->text();
        self::assertStringContainsString('refresh failed (attempt 1)', $log);
        self::assertStringContainsString('recovered', $log);
        $renderings = [$log, print_r($provider, true), (string) json_encode($provider)];
        ob_start();
        var_dump($provider);
        $renderings[] = (string) ob_get_clean();
        foreach ($renderings as $text) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $text);
            }
        }
        self::assertStringContainsString(ClientConfig::REDACTED, print_r($provider, true));

        foreach (self::failureKinds() as [$failure]) {
            $failing = $this->provider($this->transport([$this->tok('acc-secret-1', 300, 'off-secret-1'), $failure]), password: 'pw-never-logged-5d1c');
            $this->clock->advance(400);
            try {
                $failing->authorizationMetadata();
                self::fail('no error');
            } catch (KeycloakAuthenticationException $exception) {
                foreach ($secrets as $secret) {
                    self::assertStringNotContainsString($secret, $exception->getMessage());
                }
            }
            $this->clock->now = 1000.0;
        }
    }

    public function testTheDebugRenderingShowsEmptySecretsEmpty(): void
    {
        $provider = $this->provider($this->transport([$this->tok('acc-1', 300, null)]), password: '');
        $info = $provider->__debugInfo();

        self::assertSame('', $info['password']);
        self::assertSame('', $info['refreshToken']);
        self::assertSame(ClientConfig::REDACTED, $info['accessToken']);
        self::assertSame('https://kc.example.com/auth', $info['keycloakUrl']);
        self::assertFalse($info['closed']);
    }

    public function testThePasswordAndRefreshTokenParametersAreMarkedSensitive(): void
    {
        foreach ((new ReflectionMethod(KeycloakTokenProvider::class, '__construct'))->getParameters() as $parameter) {
            if (in_array($parameter->getName(), ['password', 'refreshToken'], true)) {
                self::assertCount(1, $parameter->getAttributes('SensitiveParameter'), $parameter->getName());
            }
        }
    }

    // ---------------------------------------------------------------- G. stop / close

    public function testT38StopAndCloseAreIdempotent(): void
    {
        $provider = $this->provider($this->transport([$this->tok('acc-1')]));

        $provider->stop();
        $provider->stop();
        $provider->close();

        self::assertTrue($provider->isClosed());
    }

    public function testT39TheDestructorStopsWithoutRaising(): void
    {
        $provider = $this->provider($this->transport([$this->tok('acc-1')]));
        $provider->close();
        $provider->__destruct();
        unset($provider);

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------- the update_metadata callable

    public function testTheProviderIsTheUpdateMetadataCallable(): void
    {
        $provider = $this->provider($this->transport([$this->tok('acc-1')]));

        self::assertSame(
            ['x-trace' => ['t-1'], 'authorization' => ['Bearer acc-1']],
            $provider(['x-trace' => ['t-1'], 'authorization' => ['Bearer stale']], 'aud'),
        );
        self::assertSame(['authorization' => ['Bearer acc-1']], $provider());
        self::assertSame(BearerTokenAuthenticator::HEADER, $provider->authorizationMetadata()[0]);
    }

    public function testAnExpiredTokenThatCannotBeRefreshedFailsTheCallThroughUpdateMetadata(): void
    {
        $provider = $this->provider($this->transport([$this->tok('acc-1'), FakeKeycloakTransport::refused()]));
        $this->clock->advance(400);

        $this->expectException(KeycloakAuthenticationException::class);
        $provider([]);
    }

    // ---------------------------------------------------------------- I. offline-token start-up

    public function testT42TheStartUpIsOneRefreshGrantWithTheHandedOffToken(): void
    {
        $transport = $this->transport([$this->tok('acc-1')]);
        $provider = $this->provider($transport, password: '', refreshToken: 'off-handed-0');

        self::assertSame([['grant_type' => 'refresh_token', 'client_id' => 'ondewo-nlu-cai-sdk-public', 'refresh_token' => 'off-handed-0']], array_column($transport->requests, 'form'));
        self::assertArrayNotHasKey('password', $transport->requests[0]['form']);
        self::assertSame('acc-1', $provider->accessToken());
    }

    public function testT43APasswordAlongsideTheTokenIsNeverSent(): void
    {
        $transport = $this->transport([$this->tok('acc-1'), FakeKeycloakTransport::refused(), $this->tok('acc-3')]);
        $provider = $this->provider($transport, password: 'pw-also-set', refreshToken: 'off-handed-0');

        $this->clock->advance(280);
        $provider->authorizationMetadata();
        $this->clock->advance(5);
        self::assertSame('Bearer acc-3', $provider->authorizationMetadata()[1]);

        self::assertSame(['refresh_token', 'refresh_token', 'refresh_token'], $transport->grants());
        foreach ($transport->requests as $request) {
            self::assertArrayNotHasKey('password', $request['form']);
        }
    }

    public function testT44ARefusedStartUpTokenRaisesWithoutAPasswordFallback(): void
    {
        $transport = $this->transport([FakeKeycloakTransport::refused(), $this->tok('acc-pw')]);
        try {
            $this->provider($transport, password: 'pw-also-set', refreshToken: 'off-dead');
            self::fail('no error');
        } catch (KeycloakAuthenticationException $exception) {
            self::assertStringStartsWith('Keycloak token refresh failed with status 400', $exception->getMessage());
        }

        self::assertSame(['refresh_token'], $transport->grants());
    }

    public function testT45ARotatedStartUpTokenIsUsedForTheNextRefresh(): void
    {
        $transport = $this->transport([$this->tok('acc-1', 300, 'off-rotated'), $this->tok('acc-2')]);
        $provider = $this->provider($transport, password: '', refreshToken: 'off-handed-0');

        $this->clock->advance(280);
        $provider->authorizationMetadata();

        self::assertSame('off-rotated', $transport->requests[1]['form']['refresh_token']);
    }

    public function testT46TheDeadlineIsArmedOnTheTokenPathToo(): void
    {
        $transport = $this->transport([$this->tok('acc-1'), $this->tok('acc-2'), null]);
        $provider = $this->provider($transport, tokenExpirationInS: 200, password: '', refreshToken: 'off-handed-0');

        self::assertSame(1200.0, $provider->loginDeadline());
        $this->clock->now = 1270.0;
        self::assertSame('Bearer acc-1', $provider->authorizationMetadata()[1], 'no refresh after the deadline');
        self::assertCount(1, $transport->requests);
    }

    public function testTheDeadlineIsMeasuredFromTheEndOfTheInitialAcquisition(): void
    {
        $clock = $this->clock;
        $transport = new FakeKeycloakTransport([$this->tok('acc-1')], static function () use ($clock): void {
            $clock->advance(3);
        });
        $provider = $this->provider($transport, tokenExpirationInS: 200);

        self::assertSame(1203.0, $provider->loginDeadline());
    }

    public function testT47TheTokenPathSurvivesARefusedOfflineToken(): void
    {
        $transport = $this->transport([
            $this->tok('acc-1'),
            FakeKeycloakTransport::refused(),
            FakeKeycloakTransport::refused(),
            $this->tok('acc-4'),
        ]);
        $provider = $this->provider($transport, password: '', refreshToken: 'off-handed-0');

        $this->clock->now = 1270.0;
        $provider->authorizationMetadata();
        $this->clock->now = $provider->nextRefreshAttemptAt();
        $provider->authorizationMetadata();
        $this->clock->now = $provider->nextRefreshAttemptAt();

        self::assertSame('Bearer acc-4', $provider->authorizationMetadata()[1]);
        self::assertSame(array_fill(0, 4, 'refresh_token'), $transport->grants());
    }

    // ---------------------------------------------------------------- K. positional surface

    public function testT55RefreshTokenIsTheLastConstructorParameter(): void
    {
        $parameters = (new ReflectionMethod(KeycloakTokenProvider::class, '__construct'))->getParameters();

        self::assertSame('refreshToken', end($parameters)->getName());
        self::assertSame(
            ['keycloakUrl', 'realm', 'clientId', 'username', 'password', 'tokenExpirationInS', 'verifySsl', 'transport', 'timeFn', 'randomFn', 'logger', 'refreshToken'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters),
        );
    }

    // ---------------------------------------------------------------- the production defaults

    public function testTheDefaultClockAndJitterAreUsedWhenNoneIsInjected(): void
    {
        $transport = $this->transport([$this->tok('acc-1', 0), FakeKeycloakTransport::unavailable()]);
        $before = hrtime(true) / 1e9;
        $provider = new KeycloakTokenProvider('https://kc', 'r', 'c', 'u', 'p', transport: $transport, logger: $this->logger);
        $after = hrtime(true) / 1e9;

        self::assertGreaterThanOrEqual($before, $provider->accessTokenExpiresAt());
        self::assertLessThanOrEqual($after, $provider->accessTokenExpiresAt());
        try {
            $provider->authorizationMetadata();
            self::fail('an expired token was served');
        } catch (KeycloakAuthenticationException $exception) {
            self::assertStringContainsString('status 503', $exception->getMessage());
        }
        $delay = $provider->nextRefreshAttemptAt() - $provider->accessTokenExpiresAt();
        self::assertGreaterThanOrEqual(5.0, $delay, 'the first backoff is the base whatever the jitter');
        self::assertLessThan(6.0, $delay);
    }

    public function testTheDefaultJitterStaysInsideTheWindow(): void
    {
        $provider = $this->provider($this->transport([$this->tok('acc-1')]));
        $random = (new \ReflectionProperty(KeycloakTokenProvider::class, 'randomFn'));
        $default = (new KeycloakTokenProvider('https://kc', 'r', 'c', 'u', 'p', transport: $this->transport([$this->tok('acc-1')])));
        $source = $random->getValue($default);
        for ($i = 0; $i < 200; ++$i) {
            $fraction = $source();
            self::assertGreaterThanOrEqual(0.0, $fraction);
            self::assertLessThanOrEqual(1.0, $fraction);
        }
        self::assertNotSame($random->getValue($provider), $source);
    }

    public function testWithoutALoggerTheWarningAndInfoGoToErrorLogWithTheirLevel(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ondewo-keycloak-log-');
        self::assertIsString($file);
        $previous = ini_set('error_log', $file);
        try {
            $provider = new KeycloakTokenProvider(
                'https://kc',
                'r',
                'c',
                'u',
                'p',
                transport: $this->transport([$this->tok('acc-1'), FakeKeycloakTransport::unavailable(), $this->tok('acc-2')]),
                timeFn: $this->clock,
                randomFn: static fn (): float => 1.0,
            );
            $this->clock->advance(280);
            $provider->authorizationMetadata();
            $this->clock->advance(5);
            $provider->authorizationMetadata();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $logged = (string) file_get_contents($file);
        unlink($file);

        self::assertStringContainsString('WARNING | Keycloak token refresh failed (attempt 1); retrying in 5.0s', $logged);
        self::assertStringContainsString('INFO | Keycloak token refresh recovered after 1 failed attempt(s)', $logged);
    }

    public function testAFailureAfterExpiryLogsThatTheTokenHasExpired(): void
    {
        $provider = $this->provider($this->transport([$this->tok('acc-1'), FakeKeycloakTransport::unavailable()]));
        $this->clock->advance(300);

        $this->readIgnoringExpiry($provider);

        self::assertStringStartsWith(
            'Keycloak token refresh failed (attempt 1); retrying in 5.0s, the current access token has expired (',
            $this->logger->messages('warning')[0],
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<KeycloakTokenResponse|Throwable|null> $script
     */
    private function transport(array $script): FakeKeycloakTransport
    {
        return new FakeKeycloakTransport($script);
    }

    private function tok(string $access, int|float|string $expiresIn = 300, ?string $refresh = 'off-1'): KeycloakTokenResponse
    {
        return FakeKeycloakTransport::token($access, $expiresIn, $refresh);
    }

    private function provider(
        FakeKeycloakTransport $transport,
        ?int $tokenExpirationInS = null,
        string $password = 'pw',
        string $refreshToken = '',
    ): KeycloakTokenProvider {
        return new KeycloakTokenProvider(
            'https://kc.example.com/auth',
            'ondewo-ccai-platform',
            'ondewo-nlu-cai-sdk-public',
            'user@example.com',
            $password,
            $tokenExpirationInS,
            true,
            $transport,
            $this->clock,
            static fn (): float => 1.0,
            $this->logger,
            $refreshToken,
        );
    }

    /** One read; an expired token that could not be refreshed is expected and swallowed. */
    private function readIgnoringExpiry(KeycloakTokenProvider $provider): void
    {
        try {
            $provider->authorizationMetadata();
        } catch (KeycloakAuthenticationException) {
            // the token has expired: the read is allowed to fail, the attempt is what is counted
        }
    }
}
