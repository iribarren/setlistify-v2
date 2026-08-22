<?php

declare(strict_types=1);

namespace App\Service\Streaming\Model;

/**
 * One candidate match for a `SongQuery` (AC-9.2, AC-11.1). `confidence` is a naive, explicitly
 * provisional 0–1 score (D-83, AC-11.2) — prompt 12 replaces the method that computes it, not this
 * shape. `isLive`/`isCover` are `false` rather than `null` when the provider's metadata says
 * nothing (AC-11.3): the field means "known live"/"known cover", not "unknown".
 */
final readonly class TrackCandidate
{
    public function __construct(
        public string $providerTrackId,
        public string $title,
        public string $artist,
        public ?string $album,
        /** Milliseconds. */
        public int $durationMs,
        public bool $isLive,
        public bool $isCover,
        /** Normalized 0.0–1.0, descending order within a result set (AC-11.1). */
        public float $confidence,
    ) {
    }
}
