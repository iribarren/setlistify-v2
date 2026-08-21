<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** ISO 4217 alpha-3, case-insensitive on input — the value is uppercased on write (D-28, AC-2.3). */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class CurrencyCode extends Constraint
{
    public string $message = 'This is not a valid ISO 4217 currency code.';

    public function getTargets(): string
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
