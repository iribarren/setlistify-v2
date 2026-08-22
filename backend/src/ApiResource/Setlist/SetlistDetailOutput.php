<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

/**
 * `GET /api/setlists/{setlistfmId}` (US-4). `state` distinguishes "found" from "not found on
 * setlist.fm" from "couldn't be fetched right now" (AC-4.4, AC-8.2) — a `found` setlist with zero
 * songs (`isEmpty: true`) is itself a distinct, valid outcome (AC-4.4).
 */
final readonly class SetlistDetailOutput
{
    /**
     * @param 'found'|'not_found'|'unavailable' $state
     * @param list<SongOutput>                  $songs
     */
    public function __construct(
        public string $state,
        public ?string $setlistfmId,
        public ?string $eventDate,
        public ?string $venueName,
        public ?string $venueCity,
        public ?string $venueCountry,
        public ?string $tourName,
        public bool $isEmpty,
        public array $songs,
        public FreshnessEnvelope $freshness,
    ) {
    }
}
