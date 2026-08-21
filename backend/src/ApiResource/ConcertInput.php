<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\Validator\ConcertDateRange;
use App\Validator\ValidConcertInput;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The entire request surface of `POST /api/concerts` (AC-1.2, D-29). No `owner`, `id`, `createdAt`
 * or `status` field exists to send — the owner comes from the security token, never the payload
 * (AC-7.4), and `status`/timestamps are always computed.
 */
#[ValidConcertInput]
final class ConcertInput implements ConcertInputInterface
{
    /** ISO-8601 calendar date, [1900-01-01, now+5y] (D-31, AC-9.2). */
    #[Assert\NotBlank]
    #[ConcertDateRange]
    public ?string $date = null;

    /** IANA identifier, e.g. `Europe/Madrid`. A fixed offset like `+02:00` is rejected (D-24, AC-9.3). */
    #[Assert\NotBlank]
    #[Assert\Timezone]
    public ?string $timezone = null;

    /**
     * Ordered, index 0 is the headliner (AC-1.3). 1–30 entries (D-31, AC-1.5); no duplicate band in
     * one lineup (AC-1.6) — checked by `App\Validator\ValidConcertInputValidator`.
     *
     * @var list<LineupEntryInput>
     */
    #[Assert\Count(min: 1, max: 30, minMessage: 'A concert needs at least one band.', maxMessage: 'A lineup cannot have more than {{ limit }} bands.')]
    #[Assert\Valid]
    public array $lineup = [];

    #[Assert\Valid]
    public ?VenueData $venue = null;

    #[Assert\Valid]
    public ?MoneyData $ticketPrice = null;

    /** Local wall-clock `HH:MM` in `$timezone` (AC-2.5). */
    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/', message: 'This value must be a time in HH:MM format.')]
    public ?string $doorsTime = null;

    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/', message: 'This value must be a time in HH:MM format.')]
    public ?string $startTime = null;

    /** Plain text, never rendered as HTML/Markdown (D-30). */
    #[Assert\Length(max: 2000)]
    public ?string $note = null;

    public function lineupEntries(): array
    {
        return $this->lineup;
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
