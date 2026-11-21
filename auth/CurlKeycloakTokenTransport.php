<?php

declare(strict_types=1);

namespace Ondewo\Sip\Auth;

use CurlHandle;

/**
 * The default {@see KeycloakTokenTransport}, backed by ext-curl.
 *
 * Without ext-curl the call fails with PHP's own "Call to undefined function curl_init()", which
 * {@see KeycloakTokenProvider} surfaces as a {@see KeycloakAuthenticationException}; install ext-curl
 * or pass a transport of your own.
 *
 * TLS verification is a property of THIS transport and applies to the token-endpoint request only:
 * `verifySsl: false` (for a self-signed local Envoy) never touches a process-wide setting.
 */
final class CurlKeycloakTokenTransport implements KeycloakTokenTransport
{
    /**
     * @param bool $verifySsl verify the Keycloak server's TLS certificate and host name
     */
    public function __construct(public readonly bool $verifySsl = true)
    {
    }

    public function post(string $url, array $form, float $timeoutSeconds): KeycloakTokenResponse
    {
        $handle = curl_init();
        curl_setopt_array($handle, $this->curlOptions($url, $form, $timeoutSeconds));
        $body = curl_exec($handle);

        return $this->response($handle, $body);
    }

    /**
     * The curl options of one token request. Public so that the TLS and timeout settings can be
     * asserted without a server.
     *
     * @param array<string, string> $form
     *
     * @return array<int, mixed>
     */
    public function curlOptions(string $url, array $form, float $timeoutSeconds): array
    {
        $timeoutMs = max(1, (int) ceil($timeoutSeconds * 1000));

        return [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form, '', '&', PHP_QUERY_RFC1738),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ];
    }

    /**
     * @param string|bool $body what curl_exec() returned
     *
     * @throws KeycloakTransportException if the exchange failed below HTTP
     */
    private function response(CurlHandle $handle, string|bool $body): KeycloakTokenResponse
    {
        if (!is_string($body)) {
            throw new KeycloakTransportException(
                sprintf('curl error %d: %s', curl_errno($handle), curl_error($handle))
            );
        }

        return new KeycloakTokenResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body);
    }
}
