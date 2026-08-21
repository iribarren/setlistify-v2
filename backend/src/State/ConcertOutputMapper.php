<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\BandOutput;
use App\ApiResource\ConcertOutput;
use App\ApiResource\LineupEntryOutput;
use App\ApiResource\MoneyData;
use App\ApiResource\VenueData;
use App\Entity\Concert;
use App\Service\Concert\ConcertScheduler;

/**
 * `Concert` entity → `ConcertOutput` DTO (D-29). The single place every read path
 * (`ConcertItemProvider`, `ConcertCollectionProvider`, and the create/update processors returning
 * their own response) builds the response shape, so the two can never drift.
 */
final readonly class ConcertOutputMapper
{
    public function __construct(
        private ConcertScheduler $scheduler,
    ) {
    }

    public function map(Concert $concert): ConcertOutput
    {
        $lineup = [];
        foreach ($concert->getConcertBands() as $concertBand) {
            $band = $concertBand->getBand();
            $bandId = $band->getId() ?? throw new \LogicException('Band has no id yet — not persisted.');

            $lineup[] = new LineupEntryOutput(
                band: new BandOutput($bandId, $band->getName()),
                billingOrder: $concertBand->getBillingOrder(),
            );
        }

        $venue = $concert->getVenue();
        $venueData = new VenueData($venue->getName(), $venue->getCity(), $venue->getCountryCode());

        $ticketPrice = null;
        if (null !== $concert->getPriceAmount() && null !== $concert->getPriceCurrency()) {
            $ticketPrice = new MoneyData($concert->getPriceAmount(), $concert->getPriceCurrency());
        }

        $concertId = $concert->getId() ?? throw new \LogicException('Concert has no id yet — not persisted.');

        return new ConcertOutput(
            id: $concertId,
            date: $concert->getDate()->format('Y-m-d'),
            timezone: $concert->getTimezone(),
            status: $this->scheduler->status($concert->getPastAfter()),
            lineup: $lineup,
            venue: $venueData,
            ticketPrice: $ticketPrice,
            doorsTime: $concert->getDoorsTime()?->format('H:i'),
            startTime: $concert->getStartTime()?->format('H:i'),
            note: $concert->getNote(),
            createdAt: $concert->getCreatedAt(),
            updatedAt: $concert->getUpdatedAt(),
        );
    }
}
