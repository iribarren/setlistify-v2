<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

/** One candidate row inside `CandidateSetlistsOutput`'s band (AC-1.2/AC-1.3). */
final readonly class CandidateSetlistOutput
{
    public function __construct(
        public string $setlistfmId,
        public string $eventDate,
        public ?string $venueName,
        public ?string $cityName,
        public ?string $countryCode,
        public ?string $tourName,
        public int $songCount,
        public bool $isSameNight,
        public ?string $url,
    ) {
    }
}
