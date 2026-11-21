<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Auth;

use Closure;
use Ondewo\Sip\Auth\KeycloakTokenResponse;
use Ondewo\Sip\Auth\KeycloakTokenTransport;
use RuntimeException;
use Throwable;

/**
 * A scripted token endpoint: each POST takes the next entry of the script, which is a response, an
 * exception to throw, or `null` for "repeat the last entry for ever". Every POST is recorded.
 */
final class FakeKeycloakTransport implements KeycloakTokenTransport
{
    /** @var list<array{url: string, form: array<string, string>, timeout: float}> */
    public array $requests = [];

    /** @var list<KeycloakTokenResponse|Throwable|null> */
    private array $script;

    private KeycloakTokenResponse|Throwable|null $last = null;

    /**
     * @param list<KeycloakTokenResponse|Throwable|null> $script
     * @param Closure(): void|null                       $onPost runs before every answer (e.g. advances a clock)
     */
    public function __construct(array $script, private readonly ?Closure $onPost = null)
    {
        $this->script = $script;
    }

    public function post(string $url, array $form, float $timeoutSeconds): KeycloakTokenResponse
    {
        $this->requests[] = ['url' => $url, 'form' => $form, 'timeout' => $timeoutSeconds];
        if ($this->onPost !== null) {
            ($this->onPost)();
        }
        if ($this->script !== [] && $this->script[0] !== null) {
            $this->last = array_shift($this->script);
        } elseif ($this->last === null) {
            throw new RuntimeException('the fake token endpoint ran out of script');
        }
        if ($this->last instanceof Throwable) {
            throw $this->last;
        }

        return $this->last;
    }

    /** @return list<string> the grant_type of every request, in order */
    public function grants(): array
    {
        return array_map(static fn (array $request): string => $request['form']['grant_type'], $this->requests);
    }

    /** @param array<string, mixed> $fields */
    public static function json(int $status, array $fields): KeycloakTokenResponse
    {
        return new KeycloakTokenResponse($status, (string) json_encode($fields));
    }

    public static function token(string $access, int|float|string $expiresIn = 300, ?string $refresh = 'off-1'): KeycloakTokenResponse
    {
        $fields = ['access_token' => $access, 'expires_in' => $expiresIn, 'token_type' => 'Bearer'];
        if ($refresh !== null) {
            $fields['refresh_token'] = $refresh;
        }

        return self::json(200, $fields);
    }

    /** HTTP 400 invalid_grant "Offline user session not found": a refused refresh token. */
    public static function refused(): KeycloakTokenResponse
    {
        return self::json(400, ['error' => 'invalid_grant', 'error_description' => 'Offline user session not found']);
    }

    public static function unavailable(): KeycloakTokenResponse
    {
        return self::json(503, ['error' => 'temporarily_unavailable']);
    }
}
