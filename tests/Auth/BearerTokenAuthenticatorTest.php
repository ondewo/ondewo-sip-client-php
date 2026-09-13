<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Auth;

use InvalidArgumentException;
use Ondewo\Sip\Auth\BearerTokenAuthenticator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The hand-written half of this client. Everything below is authored logic and is the ONLY
 * code the coverage gate measures (see phpunit.xml.dist `<source>`), at a 100% threshold.
 */
#[CoversClass(BearerTokenAuthenticator::class)]
final class BearerTokenAuthenticatorTest extends TestCase
{
    private const TOKEN = 'eyJhbGciOiJIUzI1NiJ9.payload.signature';

    public function testItAddsTheAuthorizationHeaderInGrpcMetadataShape(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN);

        // gRPC metadata is a map of header name -> LIST of values, not name -> string.
        self::assertSame(
            ['authorization' => ['Bearer ' . self::TOKEN]],
            $authenticator([])
        );
    }

    public function testTheMetadataArgumentIsOptional(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN);

        self::assertSame(['authorization' => ['Bearer ' . self::TOKEN]], $authenticator());
    }

    public function testItPreservesForeignMetadataAndReplacesAnExistingAuthorization(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN);

        $metadata = $authenticator([
            'authorization' => ['Bearer stale-token'],
            'x-ondewo-request-id' => ['42'],
        ], 'https://sip.ondewo.com');

        self::assertSame([
            'authorization' => ['Bearer ' . self::TOKEN],
            'x-ondewo-request-id' => ['42'],
        ], $metadata);
    }

    public function testTheTokenIsTrimmed(): void
    {
        $authenticator = new BearerTokenAuthenticator("  \t" . self::TOKEN . "\n ");

        self::assertSame(['authorization' => ['Bearer ' . self::TOKEN]], $authenticator());
    }

    #[DataProvider('blankTokens')]
    public function testABlankTokenIsRejected(string $token): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The bearer token must not be empty.');

        new BearerTokenAuthenticator($token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'whitespace' => ["\t\n\r "];
    }

    public function testChannelOptionsCarryTheAuthenticatorAndTheCredentialsKey(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN);

        $opts = $authenticator->channelOptions();

        // \Grpc\BaseStub throws unless the `credentials` key EXISTS - a null value is what
        // \Grpc\ChannelCredentials::createInsecure() itself returns, so it must survive.
        self::assertArrayHasKey('credentials', $opts);
        self::assertNull($opts['credentials']);
        self::assertSame($authenticator, $opts['update_metadata']);
        self::assertIsCallable($opts['update_metadata']);
    }

    public function testChannelOptionsPassThroughRealChannelCredentials(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN);
        $credentials = \Grpc\ChannelCredentials::createSsl();

        $opts = $authenticator->channelOptions($credentials);

        self::assertSame($credentials, $opts['credentials']);
        self::assertSame($authenticator, $opts['update_metadata']);
    }

    public function testTheHeaderAndSchemeAreTheGrpcSpelling(): void
    {
        self::assertSame('authorization', BearerTokenAuthenticator::HEADER);
        self::assertSame('Bearer', BearerTokenAuthenticator::SCHEME);
    }
}
