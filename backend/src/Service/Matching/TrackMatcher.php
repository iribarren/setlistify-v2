<?php

declare(strict_types=1);

namespace App\Service\Matching;

use App\Entity\Song;
use App\Service\Concert\BandResolver;
use App\Service\Matching\Cache\ResolvedTrack;
use App\Service\Matching\Cache\TrackResolutionStore;
use App\Service\Matching\Model\MatchOutcome;
use App\Service\Matching\Model\MatchResult;
use App\Service\Matching\Model\MatchSignals;
use App\Service\Matching\Model\NormalizedSong;
use App\Service\Matching\Similarity\ArtistSimilarity;
use App\Service\Matching\Similarity\TitleSimilarity;
use App\Service\Streaming\Model\AlbumType;
use App\Service\Streaming\Model\ArtistAuthority;
use App\Service\Streaming\Model\ProviderTokens;
use App\Service\Streaming\Model\SongQuery;
use App\Service\Streaming\Model\TrackCandidate;
use App\Service\Streaming\StreamingProviderInterface;

/**
 * The Tier 0–7 cascade (spec 12 §2) — the only public entry point into matching, mirroring
 * `App\Service\Setlist\SetlistGateway`'s single-door shape (D-58) for the same reason: a rule is
 * only as strong as its weakest caller.
 *
 * One song produces one **or more** results — a medley entry is split (Tier 0) into its constituent
 * titles, each run through the cascade independently. An ordinary entry always returns a
 * single-element list.
 *
 * At most **one** `StreamingProviderInterface::searchTrack()` call per segment (D-120) — there is
 * no speculative second search anywhere in this class. Everything provider-shaped is received
 * through the interface and `TrackCandidate`'s generic signal fields; no provider symbol appears
 * here (T-ARCH-02).
 */
final readonly class TrackMatcher
{
    /** Tier 6's REJECT floor — below this a candidate is not worth carrying in the digest at all. */
    private const float DIGEST_MIN_CONFIDENCE = 0.0;

    private const int DIGEST_SIZE = 5;

    public function __construct(
        private SongNormalizer $normalizer,
        private ArtistSimilarity $artistSimilarity,
        private MatchConfidence $matchConfidence,
        private MatchProfileRegistry $profileRegistry,
        private NonSongClassifier $nonSongClassifier,
        private MedleySplitter $medleySplitter,
        private TrackResolutionStore $resolutionStore,
    ) {
    }

    /**
     * @return list<MatchResult> one per medley segment (spec 12 §5), or a single-element list for an
     *                           ordinary entry. Empty only when Tier 0 classifies the whole entry as
     *                           a non-song, in which case exactly one `Skipped` result is returned.
     */
    public function match(
        Song $song,
        string $performingBandName,
        bool $isSetBoundary,
        StreamingProviderInterface $provider,
        ProviderTokens $tokens,
    ): array {
        // Tier 0a — isTape / the curated non-song lexicon. Checked on the WHOLE entry, before any
        // medley split: a set-boundary artifact like "Encore Break" is never itself a medley.
        if ($this->nonSongClassifier->isNonSong($song, $isSetBoundary)) {
            return [new MatchResult(MatchOutcome::Skipped, null, 0.0, false, false, null, [])];
        }

        // Tier 0b — cover attribution (D-113): search AND score against the original artist.
        $expectedArtist = $song->getCoverOfName() ?? $performingBandName;
        $isCover = null !== $song->getCoverOfName();

        // Tier 0c — medley split. A false-positive split is tolerated (spec 12 §5): each segment is
        // independently searched and either matches or is honestly reported as not found.
        $segments = $this->medleySplitter->split($song->getTitle());

        $results = [];
        foreach ($segments as $segmentTitle) {
            $results[] = $this->matchOne($segmentTitle, $expectedArtist, $isCover, $provider, $tokens);
        }

        return $results;
    }

    private function matchOne(
        string $title,
        string $expectedArtist,
        bool $isCover,
        StreamingProviderInterface $provider,
        ProviderTokens $tokens,
    ): MatchResult {
        $profile = $this->profileRegistry->forProvider($provider->key());
        $algorithmVersion = $this->profileRegistry->algorithmVersion();

        $normalizedSong = $this->normalizer->normalize($title);
        $normalizedArtist = BandResolver::normalize($expectedArtist);

        // Tier 1 — resolution cache. A hit costs zero provider calls (D-120/D-121).
        $cached = $this->resolutionStore->find($provider->key(), $algorithmVersion, $normalizedArtist, $normalizedSong->comparisonCore);
        if (null !== $cached) {
            return $this->matchResultFromCache($cached, $isCover, $expectedArtist);
        }

        // Tier 2 — the one search call this segment is allowed to spend.
        $candidates = $provider->searchTrack(new SongQuery($title, $expectedArtist), $tokens);

        if ([] === $candidates) {
            $this->resolutionStore->save($provider->key(), $algorithmVersion, $normalizedArtist, $normalizedSong->comparisonCore, null, 0.0, MatchOutcome::NotFound->value, []);

            return new MatchResult(MatchOutcome::NotFound, null, 0.0, false, $isCover, $isCover ? $expectedArtist : null, []);
        }

        // §4: version fit is absent from the formula entirely when EVERY candidate carries a
        // Version qualifier — there is nothing to prefer studio over, so a live-only song is scored
        // on its other signals exactly as a studio song would be.
        $candidateNormalizations = array_map(fn (TrackCandidate $candidate): NormalizedSong => $this->normalizer->normalize($candidate->title), $candidates);
        $anyStudioCandidateExists = self::anyWithoutVersionQualifier($candidateNormalizations);

        $titleSimilarity = new TitleSimilarity($profile->trigramWeight(), $profile->tokenSetWeight());
        $candidateCount = \count($candidates);

        $scored = [];
        foreach ($candidates as $index => $candidate) {
            $normalizedCandidate = $candidateNormalizations[$index];

            // Tier 3/4 — exact core equality after qualifier extraction short-circuits to 1.0
            // regardless of whether the artist also matched exactly; the two tiers are
            // observationally identical here, so they are not modelled as separate branches.
            $titleScore = $normalizedCandidate->comparisonCore === $normalizedSong->comparisonCore
                ? 1.0
                : $titleSimilarity->score($normalizedSong->comparisonCore, $normalizedSong->tokens, $normalizedCandidate->comparisonCore, $normalizedCandidate->tokens);

            $artistScore = $this->artistSimilarity->score($expectedArtist, $candidate->artist);
            $versionScore = $anyStudioCandidateExists ? self::versionScore($normalizedCandidate) : null;
            $releaseTypeScore = self::releaseTypeScore($candidate->albumType);
            $authorityScore = self::authorityScore($candidate->artistAuthority);
            $rankScore = 1 - ($candidate->providerRank / $candidateCount); // $candidateCount >= 1: [] === $candidates already returned above.

            $signals = new MatchSignals(
                title: $titleScore,
                artist: $artistScore,
                rank: $rankScore,
                version: $versionScore,
                duration: null, // Always absent — setlist.fm supplies no duration to compare against.
                releaseType: $releaseTypeScore,
                authority: $authorityScore,
                popularity: $candidate->popularity,
            );

            $confidence = $this->matchConfidence->score($signals, $profile);

            $scored[] = [
                'candidate' => $candidate,
                'confidence' => $confidence,
                'isLiveVersion' => 'live' === $normalizedCandidate->versionTag,
                'digest' => [
                    'providerTrackId' => $candidate->providerTrackId,
                    'title' => $candidate->title,
                    'artist' => $candidate->artist,
                    'confidence' => round($confidence, 4),
                    'signals' => [
                        'title' => round($titleScore, 4),
                        'artist' => round($artistScore, 4),
                        'version' => null !== $versionScore ? round($versionScore, 4) : null,
                        'releaseType' => null !== $releaseTypeScore ? round($releaseTypeScore, 4) : null,
                        'authority' => round($authorityScore, 4),
                        'popularity' => $candidate->popularity,
                        'rank' => round($rankScore, 4),
                    ],
                    'isLiveVersion' => 'live' === $normalizedCandidate->versionTag,
                ],
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);
        $best = $scored[0];

        $outcome = $this->matchConfidence->band((float) $best['confidence'], $profile);
        $liveVersionOnly = !$anyStudioCandidateExists && MatchOutcome::NotFound !== $outcome;

        $digest = array_slice(
            array_map(static fn (array $row): array => $row['digest'], array_filter($scored, static fn (array $row): bool => (float) $row['confidence'] > self::DIGEST_MIN_CONFIDENCE)),
            0,
            self::DIGEST_SIZE,
        );

        /** @var TrackCandidate $bestCandidate */
        $bestCandidate = $best['candidate'];
        $providerTrackId = MatchOutcome::NotFound === $outcome ? null : $bestCandidate->providerTrackId;

        // `MatchConfidence::band()` only ever bands to these three — Skipped is a Tier-0 verdict
        // this method never reaches (Tier 0 already returned above) — but its return type is the
        // full `MatchOutcome` enum, so the cache's narrower outcome type is recovered explicitly.
        $outcomeValue = match ($outcome) {
            MatchOutcome::Matched => 'matched',
            MatchOutcome::MatchedLowConfidence => 'matched_low_confidence',
            MatchOutcome::NotFound => 'not_found',
            MatchOutcome::Skipped => throw new \LogicException('MatchConfidence::band() never bands to Skipped.'),
        };

        $this->resolutionStore->save(
            $provider->key(),
            $algorithmVersion,
            $normalizedArtist,
            $normalizedSong->comparisonCore,
            $providerTrackId,
            (float) $best['confidence'],
            $outcomeValue,
            $digest,
        );

        return new MatchResult(
            outcome: $outcome,
            providerTrackId: $providerTrackId,
            confidence: (float) $best['confidence'],
            liveVersionOnly: $liveVersionOnly,
            isCover: $isCover,
            coverArtist: $isCover ? $expectedArtist : null,
            candidatesDigest: $digest,
        );
    }

    private function matchResultFromCache(ResolvedTrack $cached, bool $isCover, string $expectedArtist): MatchResult
    {
        $outcome = MatchOutcome::from($cached->outcome);

        // The digest's winning entry carries `isLiveVersion` (see matchOne()) — the cache does not
        // otherwise persist the "no studio candidate existed" fact separately, so it is recovered
        // from there rather than adding a dedicated TrackResolution column for one report note.
        $liveVersionOnly = MatchOutcome::NotFound !== $outcome && true === ($cached->candidatesDigest[0]['isLiveVersion'] ?? false);

        return new MatchResult(
            outcome: $outcome,
            providerTrackId: $cached->providerTrackId,
            confidence: $cached->confidence,
            liveVersionOnly: $liveVersionOnly,
            isCover: $isCover,
            coverArtist: $isCover ? $expectedArtist : null,
            candidatesDigest: $cached->candidatesDigest,
        );
    }

    /** @param list<NormalizedSong> $candidateNormalizations */
    private static function anyWithoutVersionQualifier(array $candidateNormalizations): bool
    {
        foreach ($candidateNormalizations as $normalized) {
            if (!$normalized->hasVersionQualifier) {
                return true;
            }
        }

        return false;
    }

    /** §4's version-fit ladder, only meaningful when at least one studio candidate exists. */
    private static function versionScore(NormalizedSong $normalizedCandidate): float
    {
        if (!$normalizedCandidate->hasVersionQualifier) {
            return 1.0; // No version tag at all reads as the studio recording.
        }

        return match ($normalizedCandidate->versionTag) {
            'studio' => 1.0,
            'demo', 'acoustic' => 0.5,
            'remix', 'instrumental', 'radio_edit' => 0.3,
            'live' => 0.0,
            default => 0.5,
        };
    }

    private static function releaseTypeScore(?AlbumType $albumType): ?float
    {
        return match ($albumType) {
            null => null,
            AlbumType::Album => 1.0,
            AlbumType::Single, AlbumType::Ep => 0.85,
            AlbumType::Compilation => 0.45,
            AlbumType::LiveAlbum => 0.30,
        };
    }

    private static function authorityScore(ArtistAuthority $authority): float
    {
        return match ($authority) {
            ArtistAuthority::Official => 1.0,
            ArtistAuthority::Verified => 0.9,
            ArtistAuthority::Unknown => 0.4,
        };
    }
}
