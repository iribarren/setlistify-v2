<?php

declare(strict_types=1);

namespace App\Service\Streaming\Model;

/**
 * What `searchTrack()` is asked to find (AC-9.2) — a normalized song name and its band, nothing
 * provider-shaped. Matching quality (normalization, ranking) is prompt 12's job (D-83); this value
 * object only carries the input.
 */
final readonly class SongQuery
{
    public function __construct(
        public string $songTitle,
        public string $bandName,
    ) {
    }
}
