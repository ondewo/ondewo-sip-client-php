<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Auth;

use InvalidArgumentException;
use Ondewo\Sip\Auth\ClientConfig;
use Ondewo\Sip\Auth\KeycloakAuthenticationException;
use Ondewo\Sip\Auth\KeycloakTokenProvider;
use Ondewo\Sip\Auth\KeycloakTokenProviderRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The shared provider registry (catalogue T48-T54, T60, T62): one provider per credential set,
 * keyed by a SHA-256 of every field, never by an object identity and never holding a secret.
 */
#[CoversClass(KeycloakTokenProviderRegistry::class)]
final class KeycloakTokenProviderRegistryTest extends TestCase
{
    /** @var list<ClientConfig> the configs the factory was called with, in order */
    private array $constructed = [];

    protected function setUp(): void
    {
        $this->constructed = [];
        KeycloakTokenProviderRegistry::setProviderFactory(function (ClientConfig $config, ?LoggerInterface $logger): KeycloakTokenProvider {
            $this->constructed[] = $config;

            return new KeycloakTokenProvider(
                $config->keycloakUrl,
                $config->realm,
                $config->clientId,
                $config->username,
                $config->password(),
                $config->tokenExpirationInS,
                $config->verifySsl,
                new FakeKeycloakTransport([FakeKeycloakTransport::token('acc-' . count($this->constructed))]),
                new FakeClock(),
                logger: $logger,
                refreshToken: $config->refreshToken(),
            );
        });
    }

    protected function tearDown(): void
    {
        KeycloakTokenProviderRegistry::setProviderFactory(null);
    }

    public function testT48TheSameCredentialsShareOneProviderConstructedOnce(): void
    {
        $first = KeycloakTokenProviderRegistry::providerFor(self::config());
        $second = KeycloakTokenProviderRegistry::providerFor(self::config());

        self::assertSame($first, $second);
        self::assertCount(1, $this->constructed);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function differingFields(): iterable
    {
        yield 'username' => [['username' => 'other@example.com']];
        yield 'password' => [['password' => 'other-pw']];
        yield 'realm' => [['realm' => 'other-realm']];
        yield 'keycloakUrl' => [['keycloakUrl' => 'https://other.example.com/auth']];
        yield 'clientId' => [['clientId' => 'other-client']];
        yield 'tokenExpirationInS' => [['tokenExpirationInS' => 600]];
        yield 'tokenExpirationInS 0 is not null' => [['tokenExpirationInS' => 0]];
        yield 'verifySsl' => [['verifySsl' => false]];
        yield 'refreshToken' => [['refreshToken' => 'off-x']];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('differingFields')]
    public function testT49EachDifferingFieldGetsItsOwnProvider(array $override): void
    {
        $base = KeycloakTokenProviderRegistry::providerFor(self::config());
        $other = KeycloakTokenProviderRegistry::providerFor(self::config($override));

        self::assertNotSame($base, $other);
        self::assertNotSame(KeycloakTokenProviderRegistry::key(self::config()), KeycloakTokenProviderRegistry::key(self::config($override)));
        self::assertCount(2, $this->constructed);
    }

    public function testATrailingSlashOnTheUrlIsTheSameCredentialSet(): void
    {
        self::assertSame(
            KeycloakTokenProviderRegistry::providerFor(self::config()),
            KeycloakTokenProviderRegistry::providerFor(self::config(['keycloakUrl' => 'https://kc.example.com/auth/'])),
        );
    }

    public function testT50ConfigsCreatedAndDroppedInALoopAreNeverConfused(): void
    {
        $usernames = [];
        for ($i = 0; $i < 50; ++$i) {
            $usernames[] = "user-$i@example.com";
            $config = self::config(['username' => "user-$i@example.com"]);
            KeycloakTokenProviderRegistry::providerFor($config);
            unset($config);
        }

        self::assertSame($usernames, array_map(static fn (ClientConfig $c): string => $c->username, $this->constructed));
    }

    public function testT51TwoTokenOnlyTenantsGetTwoProviders(): void
    {
        $a = KeycloakTokenProviderRegistry::providerFor(self::config(['password' => '', 'refreshToken' => 'off-tenant-a']));
        $b = KeycloakTokenProviderRegistry::providerFor(self::config(['password' => '', 'refreshToken' => 'off-tenant-b']));

        self::assertNotSame($a, $b);
        self::assertSame(['off-tenant-a', 'off-tenant-b'], array_map(static fn (ClientConfig $c): string => $c->refreshToken(), $this->constructed));
        self::assertSame(['', ''], array_map(static fn (ClientConfig $c): string => $c->password(), $this->constructed));
    }

    public function testT52T53TheKeyHoldsNoSecretAndIs64HexCharacters(): void
    {
        $key = KeycloakTokenProviderRegistry::key(self::config(['password' => 'pw-key-secret', 'refreshToken' => 'off-key-secret']));

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        self::assertStringNotContainsString('pw-key-secret', $key);
        self::assertStringNotContainsString('off-key-secret', $key);
        self::assertSame(
            hash('sha256', implode("\0", ['https://kc.example.com/auth', 'ondewo-ccai-platform', 'ondewo-nlu-cai-sdk-public', 'user@example.com', 'pw-key-secret', '', 'True', 'off-key-secret'])),
            $key,
            'the key derivation shared by every ONDEWO SDK',
        );
    }

    public function testT54AClosedProviderIsNeverHandedToANewClient(): void
    {
        $first = KeycloakTokenProviderRegistry::providerFor(self::config());
        $first->close();

        $second = KeycloakTokenProviderRegistry::providerFor(self::config());

        self::assertNotSame($first, $second);
        self::assertFalse($second->isClosed());
        self::assertCount(2, $this->constructed);
    }

    public function testClearClosesAndForgetsEveryProvider(): void
    {
        $first = KeycloakTokenProviderRegistry::providerFor(self::config());

        KeycloakTokenProviderRegistry::clear();

        self::assertTrue($first->isClosed());
        self::assertNotSame($first, KeycloakTokenProviderRegistry::providerFor(self::config()));
    }

    public function testAFailedInitialAcquisitionIsNotCached(): void
    {
        $attempts = 0;
        KeycloakTokenProviderRegistry::setProviderFactory(static function (ClientConfig $config) use (&$attempts): KeycloakTokenProvider {
            ++$attempts;
            $script = $attempts === 1 ? [FakeKeycloakTransport::refused()] : [FakeKeycloakTransport::token('acc-1')];

            return new KeycloakTokenProvider($config->keycloakUrl, $config->realm, $config->clientId, $config->username, $config->password(), transport: new FakeKeycloakTransport($script), timeFn: new FakeClock());
        });
        try {
            KeycloakTokenProviderRegistry::providerFor(self::config());
            self::fail('no error');
        } catch (KeycloakAuthenticationException) {
        }

        self::assertSame('acc-1', KeycloakTokenProviderRegistry::providerFor(self::config())->accessToken());
        self::assertSame(2, $attempts);
    }

    public function testT60ATokenOnlyConfigReachesTheFactoryWithItsTokenAndAnEmptyPassword(): void
    {
        $provider = KeycloakTokenProviderRegistry::providerFor(self::config(['password' => '', 'refreshToken' => 'off-handed-0']));

        self::assertSame('off-handed-0', $this->constructed[0]->refreshToken());
        self::assertSame('', $this->constructed[0]->password());
        self::assertSame(ClientConfig::REDACTED, $provider->__debugInfo()['refreshToken']);
        self::assertSame('', $provider->__debugInfo()['password']);
    }

    public function testTheLoggerOfTheCreatingCallIsHandedToTheFactory(): void
    {
        $logger = new RecordingLogger();
        $received = null;
        KeycloakTokenProviderRegistry::setProviderFactory(static function (ClientConfig $config, ?LoggerInterface $l) use (&$received): KeycloakTokenProvider {
            $received = $l;

            return new KeycloakTokenProvider($config->keycloakUrl, $config->realm, $config->clientId, $config->username, $config->password(), transport: new FakeKeycloakTransport([FakeKeycloakTransport::token('acc-1')]), timeFn: new FakeClock());
        });

        KeycloakTokenProviderRegistry::providerFor(self::config(), $logger);

        self::assertSame($logger, $received);
    }

    public function testT62AConfigWithoutKeycloakHasNoProvider(): void
    {
        $config = new ClientConfig('localhost', 50051);

        self::assertNull($config->keycloakTokenProvider());
        self::assertArrayNotHasKey('update_metadata', $config->channelOptions());
        self::assertSame([], $this->constructed);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ClientConfig for localhost:50051 has no Keycloak configuration');
        KeycloakTokenProviderRegistry::providerFor($config);
    }

    /**
     * @param array<string, mixed> $override
     */
    private static function config(array $override = []): ClientConfig
    {
        return new ClientConfig(...($override + [
            'host' => 'sip.example.com',
            'port' => 443,
            'keycloakUrl' => 'https://kc.example.com/auth',
            'realm' => 'ondewo-ccai-platform',
            'clientId' => 'ondewo-nlu-cai-sdk-public',
            'username' => 'user@example.com',
            'password' => 'pw',
        ]));
    }
}
