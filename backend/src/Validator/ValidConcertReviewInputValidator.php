<?php

declare(strict_types=1);

namespace App\Validator;

use App\ApiResource\ConcertReviewInput;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * @see ValidConcertReviewInput
 */
final class ValidConcertReviewInputValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidConcertReviewInput) {
            throw new UnexpectedTypeException($constraint, ValidConcertReviewInput::class);
        }

        if (!$value instanceof ConcertReviewInput) {
            return;
        }

        $hasNotes = null !== $value->notes && '' !== trim($value->notes);

        if (null === $value->rating && !$hasNotes) {
            // AC-1.6/D-231: an object-level violation — an empty `propertyPath` — because the
            // problem isn't either field individually, it's the combination.
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
