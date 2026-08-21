<?php

declare(strict_types=1);

namespace App\Validator;

use App\Validator\Iso\CurrencyCodes;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class CurrencyCodeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CurrencyCode) {
            throw new UnexpectedTypeException($constraint, CurrencyCode::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (!CurrencyCodes::isValid(strtoupper($value))) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
