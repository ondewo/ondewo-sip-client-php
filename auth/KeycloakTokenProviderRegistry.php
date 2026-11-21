<?php

declare(strict_types=1);

namespace Ondewo\Sip\Auth;

use Closure;
use Psr\Log\LoggerInterface;

/**
 * One {@see KeycloakTokenProvider} per credential set, shared by every client stub built from it,
 * so the initial login runs once per process and every stub reads the same refreshed token.
 *
 * The key is a SHA-256 of every field the provider is built from, never the values themselves (one
 * of them is the password) and never an object identity. `refreshToken` takes part on purpose: a
 * token-only config carries an EMPTY password, so without it two tenants' configs would collapse
 * onto one provider.
 *
 * PHP runs one request per thread, so the registry is a plain static array held for the process
 * lifetime: creation cannot race, and a provider that was {@see KeycloakTokenProvider::close()}d is
 * never handed out again - a new one is created instead.
 */
final class KeycloakTokenProviderRegistry
{
    /** @var array<string, KeycloakTokenProvider> */
    private static array $providers = [];

    /** @var (Closure(ClientConfig, ?LoggerInterface): KeycloakTokenProvider)|null */
    private static ?Closure $factory = null;

    /**
     * The shared provider of `$config`'s credential set, created (one login) on first use. The
     * logger of the call that creates it is the one it keeps.
     *
     * @throws \InvalidArgumentException       if `$config` has no Keycloak configuration
     * @throws KeycloakAuthenticationException if the initial acquisition fails; nothing is cached
     */
    public static function providerFor(ClientConfig $config, ?LoggerInterface $logger = null): KeycloakTokenProvider
    {
        if (!$config->useKeycloak()) {
            throw new \InvalidArgumentException(
                'ClientConfig for ' . $config->target() . ' has no Keycloak configuration '
                . '(keycloakUrl, realm and clientId are empty).'
            );
        }
        $key = self::key($config);
        $provider = self::$providers[$key] ?? null;
        if ($provider === null || $provider->isClosed()) {
            $provider = self::$factory !== null
                ? (self::$factory)($config, $logger)
                : new KeycloakTokenProvider(
                    $config->keycloakUrl,
                    $config->realm,
                    $config->clientId,
                    $config->username,
                    $config->password(),
                    $config->tokenExpirationInS,
                    $config->verifySsl,
                    logger: $logger,
                    refreshToken: $config->refreshToken(),
                );
            self::$providers[$key] = $provider;
        }

        return $provider;
    }

    /**
     * The registry key of `$config`: 64 hex characters, holding no substring of any field.
     */
    public static function key(ClientConfig $config): string
    {
        return hash('sha256', implode("\0", [
            $config->keycloakUrl,
            $config->realm,
            $config->clientId,
            $config->username,
            $config->password(),
            $config->tokenExpirationInS === null ? '' : (string) $config->tokenExpirationInS,
            $config->verifySsl ? 'True' : 'False',
            $config->refreshToken(),
        ]));
    }

    /** Close and forget every provider, e.g. between the jobs of a long-running worker. */
    public static function clear(): void
    {
        foreach (self::$providers as $provider) {
            $provider->close();
        }
        self::$providers = [];
    }

    /**
     * TEST SEAM: build providers with `$factory` instead of the constructor (null restores the
     * default). Clears the registry.
     *
     * @param (callable(ClientConfig, ?LoggerInterface): KeycloakTokenProvider)|null $factory
     */
    public static function setProviderFactory(?callable $factory): void
    {
        self::clear();
        self::$factory = $factory !== null ? Closure::fromCallable($factory) : null;
    }
}
