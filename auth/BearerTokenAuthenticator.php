<?php

declare(strict_types=1);

namespace Ondewo\Sip\Auth;

use InvalidArgumentException;

/**
 * Adds an ONDEWO bearer token to the metadata of every gRPC call of a generated client stub.
 *
 * This is the hand-written half of the client: the stubs under `src/` are generated from the
 * .proto definitions and know nothing about authentication, while `\Grpc\BaseStub` accepts an
 * `update_metadata` callable that it invokes for each call. An instance of this class IS that
 * callable:
 *
 * ```php
 * $client = new \Ondewo\Sip\SipClient('sip.ondewo.com:443', [
 *     'credentials'     => \Grpc\ChannelCredentials::createSsl(),
 *     'update_metadata' => new \Ondewo\Sip\Auth\BearerTokenAuthenticator($token),
 * ]);
 * ```
 *
 * The metadata format is gRPC's own: a map of header name to a LIST of values. Existing entries
 * are preserved, an existing `authorization` entry is replaced.
 */
final class BearerTokenAuthenticator
{
    public const HEADER = 'authorization';

    public const SCHEME = 'Bearer';

    private string $token;

    /**
     * @param string $token the raw token, with no `Bearer ` prefix
     *
     * @throws InvalidArgumentException if the token is empty or only whitespace
     */
    public function __construct(string $token)
    {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('The bearer token must not be empty.');
        }
        $this->token = $token;
    }

    /**
     * @param array<string, list<string>> $metadata  the call metadata gRPC collected so far
     * @param string                      $jwtAudUri unused; part of the gRPC callback signature
     *
     * @return array<string, list<string>>
     */
    public function __invoke(array $metadata = [], string $jwtAudUri = ''): array
    {
        $metadata[self::HEADER] = [self::SCHEME . ' ' . $this->token];

        return $metadata;
    }

    /**
     * The `$opts` array a generated `*Client` stub is constructed with.
     *
     * @param \Grpc\ChannelCredentials|null $credentials null selects an insecure channel, which is
     *                                                  what `\Grpc\ChannelCredentials::createInsecure()`
     *                                                  itself returns
     *
     * @return array{credentials: \Grpc\ChannelCredentials|null, update_metadata: self}
     */
    public function channelOptions($credentials = null): array
    {
        return [
            'credentials' => $credentials,
            'update_metadata' => $this,
        ];
    }
}
