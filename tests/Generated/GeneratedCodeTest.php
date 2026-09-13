<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Generated;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * PHP has no compile step, so "does the generated code build?" means "does every generated class
 * actually load, and does every proto descriptor initialise?". Loading a class parses the file and
 * resolves its parent (`\Google\Protobuf\Internal\Message`, `\Grpc\BaseStub`); `initOnce()` walks
 * the whole descriptor dependency chain, so a transitive import that was never generated fails
 * HERE instead of at a consumer's first RPC.
 *
 * This is deliberately NOT guarded by `is_dir('src')`: the stubs are committed, and a missing src/
 * has to be a red build, not a skipped one.
 */
final class GeneratedCodeTest extends TestCase
{
    /**
     * The services of ondewo-sip-api. PRODUCT-SPECIFIC: replace this list wholesale when this
     * suite is replicated to another ONDEWO client.
     *
     * @var list<class-string>
     */
    private const EXPECTED_SERVICE_CLIENTS = [
        \Ondewo\Sip\SipClient::class,
    ];

    public function testTheGeneratedStubsAreCommitted(): void
    {
        self::assertDirectoryExists(self::stubsDirectory(), 'src/ is missing - the generated stubs are not committed');

        self::assertGreaterThan(
            0,
            count(self::generatedPhpFiles()),
            'src/ holds no .php files - the generated stubs are not committed'
        );
    }

    public function testEveryGeneratedFileDeclaresAnAutoloadableClass(): void
    {
        $root = self::stubsDirectory();
        $unloadable = [];

        foreach (self::generatedPhpFiles() as $file) {
            $relative = substr($file, strlen($root) + 1, -strlen('.php'));
            $class = str_replace('/', '\\', $relative);

            if (!class_exists($class)) {
                $unloadable[] = $class;
            }
        }

        self::assertSame([], $unloadable, 'generated classes that do not load through the composer autoloader');
    }

    public function testEveryProtoDescriptorInitialises(): void
    {
        $initialised = 0;

        foreach (self::generatedPhpFiles() as $file) {
            $relative = substr($file, strlen(self::stubsDirectory()) + 1, -strlen('.php'));
            if (!str_starts_with($relative, 'GPBMetadata/')) {
                continue;
            }

            $class = str_replace('/', '\\', $relative);
            /** @var callable $initOnce */
            $initOnce = [$class, 'initOnce'];
            $initOnce();
            ++$initialised;
        }

        self::assertGreaterThan(0, $initialised, 'no GPBMetadata descriptor was found under src/');
    }

    public function testEveryExpectedServiceClientIsGenerated(): void
    {
        foreach (self::EXPECTED_SERVICE_CLIENTS as $client) {
            self::assertTrue(class_exists($client), $client . ' was not generated');
            self::assertTrue(
                is_subclass_of($client, \Grpc\BaseStub::class),
                $client . ' does not extend \Grpc\BaseStub'
            );
        }
    }

    private static function stubsDirectory(): string
    {
        return dirname(__DIR__, 2) . '/src';
    }

    /**
     * @return list<string>
     */
    private static function generatedPhpFiles(): array
    {
        $root = self::stubsDirectory();
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
