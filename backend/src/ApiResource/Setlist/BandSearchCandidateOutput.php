<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

/** One setlist.fm artist candidate (AC-1.1, AC-1.2) — never re-ranked, never silently dropped. */
final readonly class BandSearchCandidateOutput
{
    public function __construct(
        public string $mbid,
        public string $name,
        public ?string $sortName,
        public ?string $disambiguation,
    ) {
    }
}
