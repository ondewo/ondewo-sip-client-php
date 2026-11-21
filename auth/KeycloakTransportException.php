<?php

declare(strict_types=1);

namespace Ondewo\Sip\Auth;

use RuntimeException;

/**
 * Thrown by a {@see KeycloakTokenTransport} when the token endpoint could not be REACHED at all:
 * connection refused, DNS failure, TLS handshake failure, timeout, a socket error.
 *
 * {@see KeycloakTokenProvider} reports it as "could not reach the token endpoint". Any other
 * exception a transport throws is treated as a defect of that transport and is wrapped as well, but
 * under a message that does not blame the network.
 */
final class KeycloakTransportException extends RuntimeException
{
}
