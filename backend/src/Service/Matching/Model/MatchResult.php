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
    ) {
    }
}
