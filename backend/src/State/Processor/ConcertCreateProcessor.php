<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ConcertInput;
use App\ApiResource\ConcertOutput;
use App\Entity\Concert;
use App\Entity\User;
use App\Entity\Venue;
use App\Repository\ConcertRepository;
use App\Service\Concert\ConcertFieldParser;
use App\Service\Concert\ConcertScheduler;
use App\State\ConcertOutputMapper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * `POST /api/concerts` (US-1, US-2). The owner comes from the security token, never the payload
 * (AC-7.4, D-29) — `ConcertInput` has no `owner` field to attack. AC-1.8: band resolution and
 * concert persistence happen inside one transaction, so a resolution failure leaves no partial
 * concert behind.
 *
 * @implements ProcessorInterface<ConcertInput, ConcertOutput>
 */
final readonly class ConcertCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private ConcertRepository $concertRepository,
        private ConcertFieldParser $fieldParser,
        private ConcertScheduler $scheduler,
        private ConcertOutputMapper $mapper,
        private ClockInterface $clock,
        private Security $security,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ConcertOutput
    {
        // $data is already narrowed to ConcertInput here via @implements ProcessorInterface<ConcertInput, ConcertOutput>.
        $owner = $this->security->getUser();
        if (!$owner instanceof User) {
            throw new AccessDeniedHttpException();
        }

        \assert(null !== $data->date && null !== $data->timezone);

        $now = $this->clock->now();
        $date = $this->fieldParser->parseDate($data->date);
        $pastAfter = $this->scheduler->computePastAfter($date, $data->timezone);

        $concert = new Concert($owner, $date, $data->timezone, $pastAfter, $now);

        $venue = $data->venue;
        if (null !== $venue) {
            $concert->setVenue(
                new Venue($venue->name, $venue->city, null !== $venue->countryCode ? strtoupper($venue->countryCode) : null),
                $now,
            );
        }

        $price = $data->ticketPrice;
        if (null !== $price && null !== $price->amount && null !== $price->currency) {
            $concert->setPrice($price->amount, strtoupper($price->currency), $now);
        }

        $doorsTime = $this->fieldParser->parseTime($data->doorsTime);
        $startTime = $this->fieldParser->parseTime($data->startTime);
        if (null !== $doorsTime || null !== $startTime) {
            $concert->setTimes($doorsTime, $startTime, $now);
        }

        $billingOrder = 0;
        foreach ($data->lineup as $entry) {
            $concert->addLineupEntry($this->fieldParser->resolveBand($entry), $billingOrder++);
        }

        $this->entityManager->wrapInTransaction(function () use ($concert): void {
            $this->concertRepository->save($concert);
        });

        return $this->mapper->map($concert);
    }
}
