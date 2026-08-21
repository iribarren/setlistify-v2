<?php

declare(strict_types=1);

namespace App\Validator;

use App\ApiResource\ConcertInputInterface;
use App\Repository\BandRepository;
use App\Service\Concert\BandResolver;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * @see ValidConcertInput
 */
final class ValidConcertInputValidator extends ConstraintValidator
{
    public function __construct(
        private readonly BandRepository $bandRepository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidConcertInput) {
            throw new UnexpectedTypeException($constraint, ValidConcertInput::class);
        }

        if (!$value instanceof ConcertInputInterface) {
            return;
        }

        $this->validateLineup($value);
        $this->validateTicketPrice($value);
        $this->validateTimes($value);
    }

    private function validateLineup(ConcertInputInterface $value): void
    {
        /** @var array<string, int> $seenAtIndex normalized name => first index seen */
        $seenAtIndex = [];

        foreach ($value->lineupEntries() as $index => $entry) {
            $hasName = null !== $entry->name && '' !== trim($entry->name);
            $hasBandId = null !== $entry->bandId;

            if ($hasName === $hasBandId) {
                $this->context->buildViolation('Exactly one of "name" or "bandId" must be provided for each lineup entry.')
                    ->atPath(\sprintf('lineup[%d]', $index))
                    ->addViolation();

                continue;
            }

            $normalized = null;

            if ($hasName) {
                /** @var string $name */
                $name = $entry->name;
                $normalized = BandResolver::normalize($name);

                if ('' === $normalized) {
                    $this->context->buildViolation('This name does not contain any usable characters.')
                        ->atPath(\sprintf('lineup[%d].name', $index))
                        ->addViolation();

                    continue;
                }
            } else {
                /** @var int $bandId */
                $bandId = $entry->bandId;
                $band = $this->bandRepository->find($bandId);

                if (null === $band) {
                    $this->context->buildViolation('No band exists with this id.')
                        ->atPath(\sprintf('lineup[%d].bandId', $index))
                        ->addViolation();

                    continue;
                }

                $normalized = $band->getNormalizedName();
            }

            if (isset($seenAtIndex[$normalized])) {
                $this->context->buildViolation('This band already appears in the lineup.')
                    ->atPath(\sprintf('lineup[%d]', $index))
                    ->addViolation();

                continue;
            }

            $seenAtIndex[$normalized] = $index;
        }
    }

    private function validateTicketPrice(ConcertInputInterface $value): void
    {
        $price = $value->ticketPriceData();
        if (null === $price) {
            return;
        }

        $hasAmount = null !== $price->amount;
        $hasCurrency = null !== $price->currency;

        if ($hasAmount !== $hasCurrency) {
            $this->context->buildViolation('"amount" and "currency" must be provided together.')
                ->atPath('ticketPrice')
                ->addViolation();
        }
    }

    private function validateTimes(ConcertInputInterface $value): void
    {
        $doors = $value->doorsTimeValue();
        $start = $value->startTimeValue();

        if (null === $doors || null === $start) {
            return;
        }

        // Both already matched the HH:MM regex by the time this runs — string comparison is safe.
        if ($doors > $start) {
            $this->context->buildViolation('"doorsTime" must not be after "startTime".')
                ->atPath('doorsTime')
                ->addViolation();
        }
    }
}
