<?php

declare(strict_types=1);

namespace App\Service\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves which of the two refresh-token transports (D-18, AC-4.6) a request wants: the client
 * sends `X-Client-Platform: native` to receive the plaintext refresh token in the JSON response
 * body (stored in `expo-secure-store`); anything else — including no header at all — is treated as
 * `web`, which gets **only** the httpOnly cookie.
 *
 * This defaults to the safer option on purpose: if a client forgets to send the header, it gets the
 * cookie-only behaviour rather than accidentally receiving a long-lived token in a JS-readable
 * response body.
 */
final class ClientPlatform
{
    public function isNative(Request $request): bool
    {
        return 'native' === $request->headers->get('X-Client-Platform');
    }
}
