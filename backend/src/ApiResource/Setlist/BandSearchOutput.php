<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

/** `GET /api/band-searches?name=` (US-1, AC-1.1..AC-1.7). */
final readonly class BandSearchOutput
{
    /** @param list<BandSearchCandidateOutput> $candidates */
    public function __construct(
        public array $candidates,
        public FreshnessEnvelope $freshness,
    ) {
    }
}
