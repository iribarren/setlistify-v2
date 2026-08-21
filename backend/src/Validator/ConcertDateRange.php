<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * AC-9.2: `date` must be a well-formed ISO-8601 calendar date (`Y-m-d`) and fall within
 * [1900-01-01, now + 5 years] (D-31). Both bounds carry distinct messages.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ConcertDateRange extends Constraint
{
    public string $malformedMessage = 'This is not a valid calendar date (expected YYYY-MM-DD).';
    public string $tooEarlyMessage = 'The date must not be before 1900-01-01.';
    public string $tooLateMessage = 'The date must not be more than 5 years in the future.';

    public function getTargets(): string
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
