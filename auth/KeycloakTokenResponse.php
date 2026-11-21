<?php

declare(strict_types=1);

namespace Ondewo\Sip\Auth;

/**
 * What the Keycloak token endpoint answered: the HTTP status and the raw body.
 *
 * The body is credential material (it carries the access and refresh tokens), so this object
 * renders neither through `var_dump()` / `print_r()` nor through any exception message.
 */
final class KeycloakTokenResponse
{
    /**
     * @param int    $statusCode the HTTP status code
     * @param string $body       the raw response body
     */
    public function __construct(
        public readonly int $statusCode,
        #[\SensitiveParameter] private readonly string $body,
    ) {
    }

    /** The raw response body. */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * What `var_dump()` and `print_r()` show: the status and the body's length, never the body.
     *
     * @return array{statusCode: int, bodyLength: int}
     */
    public function __debugInfo(): array
    {
        return ['statusCode' => $this->statusCode, 'bodyLength' => strlen($this->body)];
    }
}
