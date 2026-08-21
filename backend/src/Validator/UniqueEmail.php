<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Request-time half of AC-1.3's uniqueness guarantee — the database unique constraint on
 * `users.email` is the other half, catching the race two simultaneous registrations create. This
 * constraint alone cannot prevent that race (TOCTOU), which is exactly why both exist.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class UniqueEmail extends Constraint
{
    public string $message = 'This email cannot be used.';

    public function getTargets(): string
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
