<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * D-231: a review must say something — `rating IS NULL AND (notes IS NULL OR blank)` is rejected.
 * A highlight alone does not satisfy this rule (a song title with no rating and no words is a data
 * artefact, not a memory).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidConcertReviewInput extends Constraint
{
    public string $message = 'A review needs a rating, some notes, or both.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
