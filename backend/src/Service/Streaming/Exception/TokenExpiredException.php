<?php

declare(strict_types=1);

namespace App\Service\Streaming\Exception;

/**
 * The stored grant is unrecoverable — revoked, expired refresh token, withdrawn scope (D-80). A
 * caller receiving this can offer "reconnect" (US-5); `App\Service\Streaming\Link\
 * StreamingTokenManager` is what actually flips the account to `needs_reauth` when a refresh fails
 * this way — this exception is what tells it (and any other caller) which kind of failure occurred.
 */
final class TokenExpiredException extends StreamingException
{
}
