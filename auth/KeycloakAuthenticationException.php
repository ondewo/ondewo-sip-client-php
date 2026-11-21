<?php

declare(strict_types=1);

namespace Ondewo\Sip\Auth;

use RuntimeException;

/**
 * The ONE error type of the Keycloak token handling: a failed login, a failed refresh and a read of
 * an access token that has expired and could not be refreshed all surface as this exception.
 *
 * What caused it (a transport error, a JSON error, a bug in an injected transport) is reachable
 * through `getPrevious()`. Its message never contains a password, a refresh token or an access
 * token: a token response is credential material, so only its field NAMES are ever quoted.
 */
final class KeycloakAuthenticationException extends RuntimeException
{
}
