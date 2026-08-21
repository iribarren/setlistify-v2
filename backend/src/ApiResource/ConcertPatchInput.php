<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\Validator\ConcertDateRange;
use App\Validator\ValidConcertInput;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * `PATCH /api/concerts/{id}` (`application/merge-patch+json`, AC-5.1). Every property is optional
 * and defaults to `null`; `App\State\Processor\ConcertUpdateProcessor` reads the *raw* decoded
 * request body to tell "the client omitted this field" (leave untouched) apart from "the client
 * cannot express this state as null anyway" — plain JSON Merge Patch semantics (RFC 7396), which a
 * bare nullable-property default cannot distinguish on its own (R-5).
 *
 * When `$lineup` IS present, it **replaces** the whole lineup (AC-5.2) — there is no partial lineup
 * edit. `owner`, `id`, `createdAt` and `status` have no property here at all (AC-5.5).
 */
#[ValidConcertInput]
final class ConcertPatchInput implements ConcertInputInterface
{
    #[ConcertDateRange]
    public ?string $date = null;

    #[Assert\Timezone]
    public ?string $timezone = null;

    /** @var list<LineupEntryInput>|null */
    #[Assert\Count(min: 1, max: 30, minMessage: 'A concert needs at least one band.', maxMessage: 'A lineup cannot have more than {{ limit }} bands.')]
    #[Assert\Valid]
    public ?array $lineup = null;

    #[Assert\Valid]
    public ?VenueData $venue = null;

    #[Assert\Valid]
    public ?MoneyData $ticketPrice = null;

    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/', message: 'This value must be a time in HH:MM format.')]
    public ?string $doorsTime = null;

    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/', message: 'This value must be a time in HH:MM format.')]
    public ?string $startTime = null;

    #[Assert\Length(max: 2000)]
    public ?string $note = null;

    public function lineupEntries(): array
    {
        return $this->lineup ?? [];
    }

    public function venueData(): ?VenueData
    {
        return $this->venue;
    }

    public function ticketPriceData(): ?MoneyData
    {
        return $this->ticketPrice;
    }

    public function doorsTimeValue(): ?string
    {
        return $this->doorsTime;
    }

    public function startTimeValue(): ?string
    {
        return $this->startTime;
    }
}
