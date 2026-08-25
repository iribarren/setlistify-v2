<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

/** One band's row inside `CandidateSetlistsOutput` (AC-1.4, AC-1.8). */
final readonly class CandidateSetlistBandOutput
{
    /** @param list<CandidateSetlistOutput> $candidates */
    public function __construct(
        public int $bandId,
        public string $bandName,
        public int $billingOrder,
        public ?string $recommendedSetlistfmId,
        public ?string $recommendedReason,
        /** Non-null only when this band has nothing (D-183) — an explanatory row, not a question. */
        public ?string $noSetlistCause,
        public array $candidates,
    ) {
    }
}
