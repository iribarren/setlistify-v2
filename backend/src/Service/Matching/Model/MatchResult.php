<?php

declare(strict_types=1);

namespace App\Service\Matching\Model;

/**
 * What `TrackMatcher::match()` returns for one (segment of a) song — provider-agnostic, carries
 * everything `MatchingStage` needs to write a `PlaylistTrack` row.
 */
final readonly class MatchResult
{
    /** @param list<array<string, mixed>> $candidatesDigest top 5 candidates + sub-scores (§8, §9) */
    public function __construct(
        public MatchOutcome $outcome,
        public ?string $providerTrackId,
        public float $confidence,
        /** True when the winning candidate is a live recording and no studio candidate existed (§4). */
        public bool $liveVersionOnly,
        /** True when this song was resolved via a cover attribution (D-113). */
        public bool $isCover,
        public ?string $coverArtist,
        public array $candidatesDigest,
        /**
         * `App\Service\Concert\BandResolver::normalize()`d expected artist and
         * `SongNormalizer`'s `comparisonCore` for this (segment of a) song — the same key
         * `TrackResolutionStore` caches under (D-121), exposed so
         * `App\Service\Playlist\Choice\PreferenceRecorder` can look up a `UserTrackPreference`
         * without re-deriving TrackMatcher's internal normalization (docs/specs/
         * 2026-08-25-playlist-normal-mode.md, D-198). Read-only metadata — never fed back into
         * matching itself.
         */
        public string $normalizedArtist = '',
        public string $normalizedTitle = '',
    ) {
    }
}
