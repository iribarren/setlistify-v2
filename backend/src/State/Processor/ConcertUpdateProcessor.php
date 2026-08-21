<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ConcertOutput;
use App\ApiResource\ConcertPatchInput;
use App\Entity\Concert;
use App\Entity\Venue;
use App\Security\Voter\ConcertVoter;
use App\Service\Concert\ConcertFieldParser;
use App\Service\Concert\ConcertScheduler;
use App\State\ConcertLocator;
use App\State\ConcertOutputMapper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `PATCH /api/concerts/{id}` (`application/merge-patch+json`, US-5). Reads the *raw* decoded
 * request body to tell "this field was omitted" (leave untouched) apart from "this field is being
 * set" — plain nullable properties on `ConcertPatchInput` cannot make that distinction on their own
 * (R-5, see the DTO's docblock). `$data`'s already-validated values supply what to set; `$raw`'s key
 * presence supplies whether to.
 *
 * Lineup replacement (AC-5.2, AC-5.3) flushes the removal of the old `ConcertBand` rows *before*
 * inserting the new ones — Doctrine's unit of work executes insertions before deletions within one
 * flush, and the new and old rows can share a `billingOrder` (each concert's billing orders are
 * `0..n-1`), which would collide against `uniq_concert_bands_concert_billing` if both were flushed
 * together.
 *
 * @implements ProcessorInterface<ConcertPatchInput, ConcertOutput>
 */
final readonly class ConcertUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private ConcertLocator $locator,
        private ConcertFieldParser $fieldParser,
        private ConcertScheduler $scheduler,
        private ConcertOutputMapper $mapper,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ConcertOutput
    {
        // $data is already narrowed to ConcertPatchInput here via @implements ProcessorInterface<ConcertPatchInput, ConcertOutput>.
        /** @var Request $request */
        $request = $context['request'];
        /** @var array<string, mixed> $raw */
        $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR | \JSON_OBJECT_AS_ARRAY);

        $concert = $this->locator->locate($uriVariables['id'] ?? null, ConcertVoter::EDIT);
        $now = $this->clock->now();

        $this->entityManager->wrapInTransaction(function () use ($data, $raw, $concert, $now): void {
            $this->applySchedule($data, $raw, $concert, $now);
            $this->applyVenue($data, $raw, $concert, $now);
            $this->applyPrice($data, $raw, $concert, $now);
            $this->applyTimes($data, $raw, $concert, $now);
            $this->applyNote($data, $raw, $concert, $now);
            $this->applyLineup($data, $raw, $concert);

            $this->entityManager->persist($concert);
            $this->entityManager->flush();
        });

        return $this->mapper->map($concert);
    }

    /** @param array<string, mixed> $raw */
    private function applySchedule(ConcertPatchInput $data, array $raw, Concert $concert, \DateTimeImmutable $now): void
    {
        $dateChanged = \array_key_exists('date', $raw);
        $timezoneChanged = \array_key_exists('timezone', $raw);

        if (!$dateChanged && !$timezoneChanged) {
            return;
        }

        $date = $dateChanged && null !== $data->date ? $this->fieldParser->parseDate($data->date) : $concert->getDate();
        $timezone = $timezoneChanged && null !== $data->timezone ? $data->timezone : $concert->getTimezone();
        $pastAfter = $this->scheduler->computePastAfter($date, $timezone);

        $concert->reschedule($date, $timezone, $pastAfter, $now);
    }

    /** @param array<string, mixed> $raw */
    private function applyVenue(ConcertPatchInput $data, array $raw, Concert $concert, \DateTimeImmutable $now): void
    {
        if (!\array_key_exists('venue', $raw)) {
            return;
        }

        $venue = $data->venue;
        $concert->setVenue(
            null === $venue
                ? Venue::empty()
                : new Venue($venue->name, $venue->city, null !== $venue->countryCode ? strtoupper($venue->countryCode) : null),
            $now,
        );
    }

    /** @param array<string, mixed> $raw */
    private function applyPrice(ConcertPatchInput $data, array $raw, Concert $concert, \DateTimeImmutable $now): void
    {
        if (!\array_key_exists('ticketPrice', $raw)) {
            return;
        }

        $price = $data->ticketPrice;
        if (null === $price || (null === $price->amount && null === $price->currency)) {
            $concert->setPrice(null, null, $now);

            return;
        }

        $concert->setPrice($price->amount, null !== $price->currency ? strtoupper($price->currency) : null, $now);
    }

    /** @param array<string, mixed> $raw */
    private function applyTimes(ConcertPatchInput $data, array $raw, Concert $concert, \DateTimeImmutable $now): void
    {
        $doorsChanged = \array_key_exists('doorsTime', $raw);
        $startChanged = \array_key_exists('startTime', $raw);

        if (!$doorsChanged && !$startChanged) {
            return;
        }

        $doorsTime = $doorsChanged ? $this->fieldParser->parseTime($data->doorsTime) : $concert->getDoorsTime();
        $startTime = $startChanged ? $this->fieldParser->parseTime($data->startTime) : $concert->getStartTime();

        $concert->setTimes($doorsTime, $startTime, $now);
    }

    /** @param array<string, mixed> $raw */
    private function applyNote(ConcertPatchInput $data, array $raw, Concert $concert, \DateTimeImmutable $now): void
    {
        if (!\array_key_exists('note', $raw)) {
            return;
        }

        $concert->setNote($data->note, $now);
    }

    /** @param array<string, mixed> $raw */
    private function applyLineup(ConcertPatchInput $data, array $raw, Concert $concert): void
    {
        if (!\array_key_exists('lineup', $raw) || null === $data->lineup) {
            return;
        }

        $concert->clearLineup();
        // Flush the removal before inserting the replacement rows — see class docblock.
        $this->entityManager->flush();

        $billingOrder = 0;
        foreach ($data->lineup as $entry) {
            $concert->addLineupEntry($this->fieldParser->resolveBand($entry), $billingOrder++);
        }
    }
}
