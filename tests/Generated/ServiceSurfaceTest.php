<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Generated;

use Grpc\BaseStub;
use Ondewo\Sip\SipClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The generated service surface, checked against the descriptors protoc compiled into the very same
 * src/ tree (see GeneratedServices). Nothing below lists the services or RPCs of an api release:
 * every service and every rpc the descriptors declare must be callable with the right streaming
 * shape, and every stub must belong to a declared service. The few explicit pins at the end are
 * "must contain" guards for the surfaces consumers depend on - they never constrain what ELSE an
 * api release may add.
 *
 * `make check_build` closes the remaining loop against the ondewo-sip-api .proto files themselves
 * (tools/check-generated-rpcs.pl), where the submodule is checked out.
 */
final class ServiceSurfaceTest extends TestCase
{
    public function testTheDescriptorsDeclareServices(): void
    {
        self::assertNotEmpty(
            GeneratedServices::fromDescriptors(),
            'no service found in any src/GPBMetadata descriptor - the stubs are missing or the descriptors unreadable'
        );
    }

    public function testEveryDeclaredServiceHasAGeneratedClient(): void
    {
        $missing = [];
        foreach (GeneratedServices::fromDescriptors() as $service) {
            if (!class_exists($service['client']) || !is_subclass_of($service['client'], BaseStub::class)) {
                $missing[] = $service['client'] . ' (' . $service['package'] . '.' . $service['service'] . ' in ' . $service['file'] . ')';
            }
        }

        self::assertSame([], $missing, 'services whose client stub is missing or does not extend \Grpc\BaseStub');
    }

    public function testEveryDeclaredRpcIsGeneratedWithItsStreamingShape(): void
    {
        $stubs = GeneratedServices::fromClientStubs();
        $failures = [];
        $checked = 0;

        foreach (GeneratedServices::fromDescriptors() as $service) {
            $client = $service['client'];
            foreach ($service['methods'] as $method) {
                ++$checked;
                $name = $client . '::' . $method['name'] . '()';
                if (!method_exists($client, $method['name'])) {
                    $failures[] = $name . ' is missing';
                    continue;
                }

                $reflected = new ReflectionMethod($client, $method['name']);
                // <request>, $metadata = [], $options = [] - except a client-streaming or bidi RPC,
                // whose requests are written to the call object: $metadata = [], $options = [].
                $takesARequest = !$method['clientStreaming'];
                $shape = [$reflected->isPublic(), $reflected->getNumberOfParameters(), $reflected->getNumberOfRequiredParameters()];
                $expected = [true, $takesARequest ? 3 : 2, $takesARequest ? 1 : 0];
                if ($shape !== $expected) {
                    $failures[] = $name . ' has the signature [public, params, required] ' . json_encode($shape) . ', expected ' . json_encode($expected);
                }

                $path = '/' . $service['package'] . '.' . $service['service'] . '/' . $method['name'];
                $helper = GeneratedServices::expectedHelper($method['clientStreaming'], $method['serverStreaming']);
                $actual = $stubs[$client][$path] ?? null;
                if ($actual !== $helper) {
                    $failures[] = $name . ' calls ' . $path . ' through ' . ($actual ?? 'nothing') . ', expected ' . $helper;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'the descriptors declare no rpc at all');
        self::assertSame([], $failures);
    }

    public function testEveryGeneratedClientServesADeclaredService(): void
    {
        $declared = [];
        foreach (GeneratedServices::fromDescriptors() as $service) {
            foreach ($service['methods'] as $method) {
                $declared[$service['client']]['/' . $service['package'] . '.' . $service['service'] . '/' . $method['name']] = true;
            }
        }

        $stray = [];
        foreach (GeneratedServices::fromClientStubs() as $client => $paths) {
            if (!isset($declared[$client])) {
                $stray[] = $client . ' belongs to no service of any descriptor';
                continue;
            }
            foreach (array_keys($paths) as $path) {
                if (!isset($declared[$client][$path])) {
                    $stray[] = $client . ' calls ' . $path . ', which no descriptor declares';
                }
            }
        }

        self::assertSame([], $stray, 'stale stubs: regenerate src/ with `make generate_ondewo_protos`');
    }

    public function testEveryGeneratedClientStubFileDeclaresItsClass(): void
    {
        foreach (array_keys(GeneratedServices::fromClientStubs()) as $client) {
            self::assertTrue(class_exists($client), $client . ' does not load');
            $file = (new ReflectionClass($client))->getFileName();
            self::assertIsString($file);
            self::assertStringStartsWith(GeneratedServices::stubsDirectory() . '/', $file);
        }
    }

    /**
     * "Must contain" pins: the SIP service every consumer of this package talks to, and one RPC
     * of each streaming shape it declares (ondewo.sip has no server- or client-only streaming RPC).
     * Additions in a later api release never break this test.
     */
    public function testTheCoreSipServicesAreDeclared(): void
    {
        $sip = [];
        foreach (GeneratedServices::fromDescriptors() as $service) {
            if ($service['package'] === 'ondewo.sip') {
                $sip[] = $service['service'];
            }
        }

        foreach (['Sip'] as $required) {
            self::assertContains($required, $sip, 'ondewo.sip.' . $required . ' is no longer generated');
        }
    }

    public function testOneRpcOfEachStreamingShapeIsPinned(): void
    {
        $shapes = [];
        foreach (GeneratedServices::fromDescriptors() as $service) {
            foreach ($service['methods'] as $method) {
                $shapes[$service['client'] . '::' . $method['name']] =
                    GeneratedServices::expectedHelper($method['clientStreaming'], $method['serverStreaming']);
            }
        }

        self::assertSame('_simpleRequest', $shapes[SipClient::class . '::SipStartSession'] ?? null);
        self::assertSame('_simpleRequest', $shapes[SipClient::class . '::SipGetSipStatus'] ?? null);
        self::assertSame('_bidiRequest', $shapes[SipClient::class . '::SipStreamCallAudio'] ?? null);
    }
}
