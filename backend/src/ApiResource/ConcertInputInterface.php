<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * The subset of `ConcertInput`/`ConcertPatchInput` that `App\Validator\ValidConcertInputValidator`
 * needs for cross-field checks (lineup duplicates, band-name-normalizes-empty, ticket-price pair,
 * doors/start ordering) — shared so one validator serves both create and update.
 */
interface ConcertInputInterface
{
    /** @return list<LineupEntryInput> */
    public function lineupEntries(): array;

    public function venueData(): ?VenueData;

    public function ticketPriceData(): ?MoneyData;

    public function doorsTimeValue(): ?string;

    public function startTimeValue(): ?string;
}
