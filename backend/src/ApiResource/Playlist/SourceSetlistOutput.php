<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

/**
 * One setlist.fm setlist a `Playlist` was built from (D-185) — one entry per distinct
 * `(sourceBand, sourceSetlistfmId)` among the playlist's tracks, in first-appearance (playing)
 * order. `url` is `null` for a setlist cached before the `Setlist.url` column existed (D-186,
 * no backfill).
 */
final readonly class SourceSetlistOutput
{
    public function __construct(
        public string $bandName,
        public string $setlistfmId,
        public ?string $url,
    ) {
    }
}
