<?php

declare(strict_types=1);

namespace App\Validator;

use App\Repository\UserRepository;
use App\Service\Security\EmailNormalizer;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class UniqueEmailValidator extends ConstraintValidator
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EmailNormalizer $emailNormalizer,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueEmail) {
            throw new UnexpectedTypeException($constraint, UniqueEmail::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $normalized = $this->emailNormalizer->normalize($value);

        if (null !== $this->userRepository->findOneByEmail($normalized)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
