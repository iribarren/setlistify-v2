<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * The single place email addresses are trimmed and lowercased before comparison or persistence
 * (AC-1.3). Both `RegisterUserProcessor` and `LoginProcessor` (and anything else that looks a user
 * up by email) must go through this rather than normalizing inline.
 */
final class EmailNormalizer
{
    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
