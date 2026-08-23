<?php

declare(strict_types=1);

namespace App\Service\Streaming\Model;

/**
 * One candidate match for a `SongQuery` (AC-9.2, AC-11.1). `isLive`/`isCover` are `false` rather
 * than `null` when the provider's metadata says nothing (AC-11.3): the field means "known
 * live"/"known cover", not "unknown".
 *
 * **`confidence` is an ordering hint only, derived from the provider's own result rank — it is
 * never read by `App\Service\Matching\MatchConfidence`** (D-147). Prompt 12's scorer computes its
 * own number from `providerRank` and the other signal fields below; nothing here is authoritative
 * about match quality.
 *
 * **The five fields from `artistAuthority` onward are new relative to prompt 10 (D-119/D-147)** —
 * generic, provider-agnostic signal inputs to `App\Service\Matching\MatchConfidence`. Every adapter
 * may leave any of them at their default (`Unknown`/`null`/`0`) when the provider's metadata does
 * not supply an equivalent; an absent signal drops out of the confidence formula's renormalization
 * rather than scoring as zero. Appended with defaults so no call site outside
 * `Service/Streaming/<Provider>/` needed to change when they were added.
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
        /** Ordering hint only — see class docblock. Normalized 0.0–1.0, descending within a result set. */
        public float $confidence,
        public ArtistAuthority $artistAuthority = ArtistAuthority::Unknown,
        public ?AlbumType $albumType = null,
        /** Provider popularity, normalized 0.0–1.0 by the adapter, or null when unsupplied. */
        public ?float $popularity = null,
        /** Carried, not consumed by matching — for the report/backoffice only. */
        public ?string $isrc = null,
        /** 0-based position in the provider's own result order (spec 12 §3 signal 8). */
        public int $providerRank = 0,
    ) {
    }
}
