<?php

declare(strict_types=1);

namespace Ondewo\Sip\Auth;

/**
 * The HTTP exchange with the Keycloak token endpoint, injectable so that the token handling can be
 * tested without a network. The default is {@see CurlKeycloakTokenTransport}.
 */
interface KeycloakTokenTransport
{
    /**
     * POST `$form` as `application/x-www-form-urlencoded` to `$url` and return what came back,
     * whatever its status.
     *
     * @param string                $url            the token endpoint
     * @param array<string, string> $form           the form fields
     * @param float                 $timeoutSeconds bound of the WHOLE exchange (connect + read)
     *
     * @throws KeycloakTransportException if the endpoint could not be reached (connection refused,
     *                                    DNS, TLS handshake, timeout, socket error)
     */
    public function post(string $url, array $form, float $timeoutSeconds): KeycloakTokenResponse;
}
