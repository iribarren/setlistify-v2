<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

/**
 * `GET /api/bands/{bandId}/setlists` (US-3). `state` carries the band's setlist.fm resolution
 * outcome (US-2, US-5) — an unresolved/ambiguous/no_presence band returns a 200 with an explicit
 * state and no setlists, never an empty page pretending to mean "no shows" (AC-3.4, AC-5.3).
 */
final readonly class BandSetlistsOutput
{
    /**
     * @param list<BandSearchCandidateOutput> $candidates
     * @param list<SetlistSummaryOutput>      $setlists
     */
    public function __construct(
        /** One of 'resolved'|'ambiguous'|'no_presence'|'unresolved' (mirrors `Band::RESOLUTION_*`). */
        public string $state,
        public array $candidates,
        public array $setlists,
        public int $totalItems,
        public int $page,
        public int $itemsPerPage,
        public FreshnessEnvelope $freshness,
    ) {
    }
}
