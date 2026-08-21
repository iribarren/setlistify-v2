<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * Thrown for every refresh-token failure — unknown token, expired token, and (after the grace
 * window) replay. Deliberately a single exception type: AC-4.8 requires the response to never
 * distinguish "unknown" from "expired", and reuse must not leak a different shape either.
 */
final class RefreshTokenInvalidException extends \RuntimeException
{
}
