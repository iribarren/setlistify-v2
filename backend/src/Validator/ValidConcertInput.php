<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level cross-field checks on `App\ApiResource\ConcertInput` / `ConcertPatchInput` that a
 * single property constraint cannot express: lineup entry shape and duplicates (AC-1.6, AC-9.4),
 * the `ticketPrice` amount/currency pair (AC-2.3), and `doorsTime <= startTime` (AC-2.5).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidConcertInput extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
