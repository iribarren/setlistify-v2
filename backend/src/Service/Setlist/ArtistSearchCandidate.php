<?php

declare(strict_types=1);

namespace App\Service\Setlist;

/** One candidate from setlist.fm's artist search (AC-1.1), in setlist.fm's own relevance order. */
final readonly class ArtistSearchCandidate
{
    public function __construct(
        public string $mbid,
        public string $name,
        public ?string $sortName,
        public ?string $disambiguation,
        public ?string $url,
    ) {
    }
}
