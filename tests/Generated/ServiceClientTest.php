<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Generated;

use Grpc\BaseStub;
use Grpc\ChannelCredentials;
use Ondewo\Sip\Auth\BearerTokenAuthenticator;
use Ondewo\Sip\SipClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Constructs the generated service stubs against a dummy target. gRPC channels connect lazily, so
 * nothing here touches the network - but the stub, its channel options and its method surface are
 * all real.
 *
 * The set of service clients is DERIVED from the generated descriptors (GeneratedServices); only
 * `SipClient`, used to exercise the channel options, is named here.
 */
final class ServiceClientTest extends TestCase
{
    private const DUMMY_TARGET = 'localhost:50051';

    /**
     * @var list<BaseStub>
     */
    private array $openClients = [];

    protected function tearDown(): void
    {
        foreach ($this->openClients as $client) {
            $client->close();
        }
        $this->openClients = [];

        parent::tearDown();
    }

    public function testAServiceClientIsConstructedAgainstAnInsecureChannel(): void
    {
        $client = $this->open(new SipClient(self::DUMMY_TARGET, [
            'credentials' => ChannelCredentials::createInsecure(),
        ]));

        self::assertInstanceOf(BaseStub::class, $client);
        // Contains, not equals: gRPC canonicalises the target (`dns:///localhost:50051`) in some
        // core versions.
        self::assertStringContainsString(self::DUMMY_TARGET, $client->getTarget());
    }

    public function testAServiceClientAcceptsTheHandWrittenBearerAuthenticator(): void
    {
        $authenticator = new BearerTokenAuthenticator('a-token');

        // The point of the hand-written auth surface: its output IS a valid `$opts` array for a
        // generated stub. \Grpc\BaseStub rejects a missing `credentials` key and a non-callable
        // `update_metadata`, so constructing successfully proves both.
        $client = $this->open(new SipClient(self::DUMMY_TARGET, $authenticator->channelOptions()));

        self::assertStringContainsString(self::DUMMY_TARGET, $client->getTarget());
    }

    /**
     * @param class-string<BaseStub> $client
     */
    #[DataProvider('declaredServiceClients')]
    public function testEveryDeclaredServiceClientIsConstructedAgainstAnInsecureChannel(string $client): void
    {
        $stub = $this->open(new $client(self::DUMMY_TARGET, [
            'credentials' => ChannelCredentials::createInsecure(),
        ]));

        self::assertInstanceOf(BaseStub::class, $stub);
        self::assertStringContainsString(self::DUMMY_TARGET, $stub->getTarget());
    }

    /**
     * Every service the generated descriptors declare - derived, not listed, so a service added by
     * the next ondewo-sip-api release is constructed here without anybody editing this file.
     * The method surface itself is ServiceSurfaceTest's job.
     *
     * @return iterable<string, array{class-string<BaseStub>}>
     */
    public static function declaredServiceClients(): iterable
    {
        foreach (GeneratedServices::fromDescriptors() as $service) {
            /** @var class-string<BaseStub> $client */
            $client = $service['client'];
            yield $service['package'] . '.' . $service['service'] => [$client];
        }
    }

    private function open(BaseStub $client): BaseStub
    {
        $this->openClients[] = $client;

        return $client;
    }
}
