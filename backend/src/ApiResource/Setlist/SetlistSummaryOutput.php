<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

/** One entry in a band's setlist index (AC-3.2). */
final readonly class SetlistSummaryOutput
{
    public function __construct(
        public string $setlistfmId,
        public string $eventDate,
        public ?string $venueName,
        public ?string $venueCity,
        public ?string $venueCountry,
        public ?string $tourName,
        public int $songCount,
    ) {
    }
}
