<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Generated;

use FilesystemIterator;
use Google\Protobuf\Internal\FileDescriptorProto;
use Google\Protobuf\Internal\FileDescriptorSet;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The service surface of the generated code, read back out of the code itself - so the API-surface
 * tests DERIVE their expectations instead of carrying hand-written lists that break (or, worse,
 * silently stop covering) on the next ondewo-sip-api release.
 *
 * Two independent sources, compared against each other by ServiceSurfaceTest:
 *   - the serialized FileDescriptorSet every src/GPBMetadata/** class registers with
 *     `internalAddGeneratedFile(...)` - i.e. what protoc saw in the .proto files: every service,
 *     every rpc and whether each side of it streams;
 *   - the generated src/**\/*Client.php stubs, i.e. what a consumer can actually call.
 */
final class GeneratedServices
{
    /**
     * @var list<array{file: string, package: string, service: string, client: string, methods: list<array{name: string, clientStreaming: bool, serverStreaming: bool}>}>|null
     */
    private static ?array $descriptorServices = null;

    /**
     * Every service of every generated descriptor.
     *
     * @return list<array{file: string, package: string, service: string, client: string, methods: list<array{name: string, clientStreaming: bool, serverStreaming: bool}>}>
     */
    public static function fromDescriptors(): array
    {
        if (self::$descriptorServices !== null) {
            return self::$descriptorServices;
        }

        $services = [];
        $files = [];
        foreach (self::phpFiles(self::stubsDirectory() . '/GPBMetadata') as $metadataFile) {
            $set = new FileDescriptorSet();
            $set->mergeFromString(self::serializedDescriptor($metadataFile));
            foreach ($set->getFile() as $file) {
                $files[] = $file;
            }
        }

        foreach ($files as $file) {
            $package = $file->getPackage();
            $namespace = self::phpNamespace($file);
            foreach ($file->getService() as $service) {
                $methods = [];
                foreach ($service->getMethod() as $method) {
                    $methods[] = [
                        'name' => $method->getName(),
                        'clientStreaming' => $method->getClientStreaming(),
                        'serverStreaming' => $method->getServerStreaming(),
                    ];
                }
                $services[] = [
                    'file' => $file->getName(),
                    'package' => $package,
                    'service' => $service->getName(),
                    'client' => $namespace . '\\' . $service->getName() . 'Client',
                    'methods' => $methods,
                ];
            }
        }

        return self::$descriptorServices = $services;
    }

    /**
     * Every generated *Client.php, as class name => the method paths (/<package>.<Service>/<Rpc>) it
     * calls, each with the BaseStub helper it calls it through.
     *
     * @return array<string, array<string, string>>
     */
    public static function fromClientStubs(): array
    {
        $root = self::stubsDirectory();
        $clients = [];
        foreach (self::phpFiles($root) as $path) {
            if (!str_ends_with($path, 'Client.php') || str_starts_with($path, $root . '/GPBMetadata/')) {
                continue;
            }
            $class = str_replace('/', '\\', substr($path, strlen($root) + 1, -strlen('.php')));
            preg_match_all("/->(_\\w+Request)\\(\\s*'(\\/[\\w.]+\\/\\w+)'/", (string) file_get_contents($path), $calls, PREG_SET_ORDER);
            $clients[$class] = [];
            foreach ($calls as [, $helper, $methodPath]) {
                $clients[$class][$methodPath] = $helper;
            }
        }
        ksort($clients);

        return $clients;
    }

    /**
     * The \Grpc\BaseStub helper a generated method has to call for its streaming shape.
     */
    public static function expectedHelper(bool $clientStreaming, bool $serverStreaming): string
    {
        return match (true) {
            $clientStreaming && $serverStreaming => '_bidiRequest',
            $clientStreaming => '_clientStreamRequest',
            $serverStreaming => '_serverStreamRequest',
            default => '_simpleRequest',
        };
    }

    public static function stubsDirectory(): string
    {
        return dirname(__DIR__, 2) . '/src';
    }

    /**
     * protoc's php generator: the php_namespace option when set, otherwise the package with every
     * dot-separated segment upper-camel-cased (ondewo.sip -> Ondewo\Sip).
     */
    private static function phpNamespace(FileDescriptorProto $file): string
    {
        $options = $file->getOptions();
        if ($options !== null && $options->hasPhpNamespace()) {
            return $options->getPhpNamespace();
        }

        return implode('\\', array_map(
            static fn (string $segment): string => ucfirst($segment),
            explode('.', $file->getPackage()),
        ));
    }

    /**
     * The serialized FileDescriptorSet a GPBMetadata class hands to internalAddGeneratedFile(): its
     * first argument, a double-quoted literal of printable characters and \xHH / \" / \\ / \$
     * escapes.
     */
    private static function serializedDescriptor(string $metadataFile): string
    {
        $tokens = token_get_all((string) file_get_contents($metadataFile));
        $seenCall = false;
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_STRING && $token[1] === 'internalAddGeneratedFile') {
                $seenCall = true;
            } elseif ($seenCall && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                return stripcslashes(substr($token[1], 1, -1));
            }
        }

        throw new RuntimeException($metadataFile . ' registers no serialized descriptor');
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        /** @var SplFileInfo $entry */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
