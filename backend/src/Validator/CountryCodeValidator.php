<?php

declare(strict_types=1);

namespace App\Validator;

use App\Validator\Iso\CountryCodes;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class CountryCodeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CountryCode) {
            throw new UnexpectedTypeException($constraint, CountryCode::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (!CountryCodes::isValid(strtoupper($value))) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
