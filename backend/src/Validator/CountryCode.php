<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** ISO 3166-1 alpha-2, case-insensitive on input — the value is uppercased on write (D-26, AC-2.2). */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class CountryCode extends Constraint
{
    public string $message = 'This is not a valid ISO 3166-1 alpha-2 country code.';

    public function getTargets(): string
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
