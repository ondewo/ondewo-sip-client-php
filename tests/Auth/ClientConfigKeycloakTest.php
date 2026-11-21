<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Auth;

use Grpc\BaseStub;
use InvalidArgumentException;
use Ondewo\Sip\Auth\ClientConfig;
use Ondewo\Sip\Auth\KeycloakAuthenticationException;
use Ondewo\Sip\Auth\KeycloakTokenProvider;
use Ondewo\Sip\Auth\KeycloakTokenProviderRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The Keycloak half of ClientConfig (catalogue T55-T62): validation, redaction, the positional
 * surface and the provider channelOptions() attaches.
 */
#[CoversClass(ClientConfig::class)]
final class ClientConfigKeycloakTest extends TestCase
{
    /** @var list<ClientConfig> */
    private array $constructed = [];

    protected function setUp(): void
    {
        $this->constructed = [];
        KeycloakTokenProviderRegistry::setProviderFactory(function (ClientConfig $config): KeycloakTokenProvider {
            $this->constructed[] = $config;
            $script = $config->password() === 'refused-pw' ? [FakeKeycloakTransport::refused()] : [FakeKeycloakTransport::token('acc-1')];

            return new KeycloakTokenProvider(
                $config->keycloakUrl,
                $config->realm,
                $config->clientId,
                $config->username,
                $config->password(),
                transport: new FakeKeycloakTransport($script),
                timeFn: new FakeClock(),
                refreshToken: $config->refreshToken(),
            );
        });
    }

    protected function tearDown(): void
    {
        KeycloakTokenProviderRegistry::setProviderFactory(null);
    }

    public function testT55RefreshTokenIsTheLastConstructorParameterAndTheOldOnesKeepTheirPlace(): void
    {
        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(ClientConfig::class, '__construct'))->getParameters(),
        );

        self::assertSame(
            ['host', 'port', 'grpcCert', 'grpcClientCert', 'grpcClientKey', 'useSecureChannel',
                'keycloakUrl', 'realm', 'clientId', 'username', 'password', 'tokenExpirationInS', 'verifySsl', 'refreshToken'],
            $names,
        );
    }

    public function testThePasswordAndRefreshTokenParametersAreMarkedSensitive(): void
    {
        foreach ((new ReflectionMethod(ClientConfig::class, '__construct'))->getParameters() as $parameter) {
            if (in_array($parameter->getName(), ['password', 'refreshToken'], true)) {
                self::assertCount(1, $parameter->getAttributes('SensitiveParameter'), $parameter->getName());
            }
        }
    }

    public function testT56ATokenOnlyConfigConstructsAndUsesKeycloak(): void
    {
        $config = self::config(['password' => '', 'refreshToken' => 'off-handed-0']);

        self::assertTrue($config->useKeycloak());
        self::assertSame('off-handed-0', $config->refreshToken());
        self::assertSame('', $config->password());
    }

    public function testT57KeycloakWithNeitherPasswordNorTokenIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either the field `password` or the field `refreshToken` is mandatory in ClientConfig.');
        self::config(['password' => '']);
    }

    public function testT58APasswordConfigIsUnchanged(): void
    {
        $config = self::config();

        self::assertTrue($config->useKeycloak());
        self::assertSame('pw-secret-1', $config->password());
        self::assertSame('', $config->refreshToken());
        self::assertNull($config->tokenExpirationInS);
        self::assertTrue($config->verifySsl);
    }

    public function testBothCredentialsAreAllowed(): void
    {
        $config = self::config(['refreshToken' => 'off-handed-0']);

        self::assertSame('pw-secret-1', $config->password());
        self::assertSame('off-handed-0', $config->refreshToken());
    }

    public function testTheKeycloakUrlLosesItsTrailingSlashes(): void
    {
        self::assertSame('https://kc.example.com/auth', self::config(['keycloakUrl' => 'https://kc.example.com/auth//'])->keycloakUrl);
    }

    public function testT59EveryRenderingRedactsThePasswordAndTheRefreshToken(): void
    {
        $config = self::config(['refreshToken' => 'off-secret-1']);
        ob_start();
        var_dump($config);
        $dumped = (string) ob_get_clean();

        foreach (['string' => (string) $config, 'print_r' => print_r($config, true), 'var_dump' => $dumped, 'json' => (string) json_encode($config)] as $rendering => $text) {
            self::assertStringNotContainsString('pw-secret-1', $text, $rendering);
            self::assertStringNotContainsString('off-secret-1', $text, $rendering);
            self::assertStringContainsString(ClientConfig::REDACTED, $text, $rendering);
        }
        self::assertSame(ClientConfig::REDACTED, $config->jsonSerialize()['password']);
        self::assertSame(ClientConfig::REDACTED, $config->jsonSerialize()['refreshToken']);
        self::assertSame('user@example.com', $config->jsonSerialize()['username']);
    }

    public function testT59AnEmptySecretRendersEmptyNotRedacted(): void
    {
        $tokenOnly = self::config(['password' => '', 'refreshToken' => 'off-secret-1']);
        $passwordOnly = self::config();

        self::assertSame('', $tokenOnly->jsonSerialize()['password']);
        self::assertSame(ClientConfig::REDACTED, $tokenOnly->jsonSerialize()['refreshToken']);
        self::assertSame('', $passwordOnly->jsonSerialize()['refreshToken']);
    }

    public function testT60ATokenOnlyConfigReachesTheProviderFactoryWithItsTokenAndAnEmptyPassword(): void
    {
        $provider = self::config(['password' => '', 'refreshToken' => 'off-handed-0'])->keycloakTokenProvider();

        self::assertInstanceOf(KeycloakTokenProvider::class, $provider);
        self::assertCount(1, $this->constructed);
        self::assertSame('off-handed-0', $this->constructed[0]->refreshToken());
        self::assertSame('', $this->constructed[0]->password());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function partialTriples(): iterable
    {
        yield 'url only' => [['realm' => '', 'clientId' => '']];
        yield 'realm only' => [['keycloakUrl' => '', 'clientId' => '']];
        yield 'client id only' => [['keycloakUrl' => '', 'realm' => '']];
        yield 'url and realm' => [['clientId' => '']];
        yield 'url and client id' => [['realm' => '']];
        yield 'realm and client id' => [['keycloakUrl' => '']];
        yield 'a url of only slashes' => [['keycloakUrl' => '///']];
    }

    /**
     * @param array<string, string> $override
     */
    #[DataProvider('partialTriples')]
    public function testT61APartialTripleIsRefusedNamingTheThreeFields(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The Keycloak fields `keycloakUrl`, `realm`, and `clientId` must be provided together in ClientConfig.'
        );
        self::config($override);
    }

    public function testT62NoKeycloakTripleMeansNoProviderAndNoAuthorizationMetadata(): void
    {
        $config = new ClientConfig('localhost', 50051, username: 'only-a-user', password: '');

        self::assertFalse($config->useKeycloak());
        self::assertNull($config->keycloakTokenProvider());
        self::assertArrayNotHasKey('update_metadata', $config->channelOptions());
        self::assertSame([], $this->constructed);
    }

    public function testWithoutKeycloakNeitherPasswordNorTokenIsRequired(): void
    {
        $config = new ClientConfig('localhost', 50051);

        self::assertSame('', $config->password());
        self::assertSame('', $config->refreshToken());
        self::assertFalse($config->useKeycloak());
    }

    public function testChannelOptionsAttachTheSharedProviderAsUpdateMetadata(): void
    {
        $config = self::config();
        $options = $config->channelOptions();

        self::assertInstanceOf(KeycloakTokenProvider::class, $options['update_metadata']);
        self::assertSame($options['update_metadata'], self::config()->channelOptions()['update_metadata'], 'one login per credential set');
        self::assertSame(['authorization' => ['Bearer acc-1']], ($options['update_metadata'])([]));
        self::assertCount(1, $this->constructed);
    }

    public function testAnUpdateMetadataOfTheCallerWinsAndBuildsNoProvider(): void
    {
        $mine = static fn (array $metadata): array => $metadata;

        self::assertSame($mine, self::config()->channelOptions(['update_metadata' => $mine])['update_metadata']);
        self::assertSame([], $this->constructed);
    }

    public function testARefusedCredentialFailsInChannelOptionsNotOnTheFirstCall(): void
    {
        $this->expectException(KeycloakAuthenticationException::class);
        $this->expectExceptionMessage('Keycloak token login failed with status 400');
        self::config(['password' => 'refused-pw'])->channelOptions();
    }

    public function testAGeneratedStubAcceptsTheKeycloakChannelOptions(): void
    {
        $config = self::config(['host' => 'localhost', 'port' => 50051, 'useSecureChannel' => false]);
        $options = $config->channelOptions(logger: new RecordingLogger());
        $stub = new \Ondewo\Sip\SipClient($config->target(), $options);

        self::assertInstanceOf(BaseStub::class, $stub);
        $stub->close();
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
            'password' => 'pw-secret-1',
        ]));
    }
}
