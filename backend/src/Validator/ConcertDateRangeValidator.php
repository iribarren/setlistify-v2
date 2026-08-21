<?php

declare(strict_types=1);

namespace App\Validator;

use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ConcertDateRangeValidator extends ConstraintValidator
{
    private const string MIN_DATE = '1900-01-01';
    private const string MAX_YEARS_AHEAD = '+5 years';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ConcertDateRange) {
            throw new UnexpectedTypeException($constraint, ConcertDateRange::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $this->context->buildViolation($constraint->malformedMessage)->addViolation();

            return;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $value) {
            $this->context->buildViolation($constraint->malformedMessage)->addViolation();

            return;
        }

        $min = \DateTimeImmutable::createFromFormat('!Y-m-d', self::MIN_DATE, new \DateTimeZone('UTC'));
        \assert(false !== $min);
        if ($date < $min) {
            $this->context->buildViolation($constraint->tooEarlyMessage)->addViolation();

            return;
        }

        $max = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify(self::MAX_YEARS_AHEAD);
        if ($date > $max) {
            $this->context->buildViolation($constraint->tooLateMessage)->addViolation();
        }
    }
}
