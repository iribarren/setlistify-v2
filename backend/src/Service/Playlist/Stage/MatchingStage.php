<?php

declare(strict_types=1);

namespace App\Service\Playlist\Stage;

use App\Entity\Playlist;
use App\Entity\PlaylistGenerationJob;
use App\Entity\PlaylistTrack;
use App\Entity\Setlist;
use App\Entity\Song;
use App\Service\Matching\Model\MatchOutcome;
use App\Service\Matching\Model\MatchResult;
use App\Service\Matching\TrackMatcher;
use App\Service\Playlist\Choice\PreferenceRecorder;
use App\Service\Playlist\Choice\StalenessReconciler;
use App\Service\Playlist\Exception\GenerationBlockedException;
use App\Service\Playlist\JobProgressWriter;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\PipelineStage;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\TrackOutcome;
use App\Service\Streaming\Exception\QuotaExhaustedException;
use App\Service\Streaming\Exception\RateLimitedException;
use App\Service\Streaming\Model\ProviderTokens;
use App\Service\Streaming\StreamingProviderInterface;
use Psr\Clock\ClockInterface;

/**
 * `TrackMatcher::match()` per song, cache-first, sequential (spec 14 §3). Writes each song's
 * `PlaylistTrack` row and `songsProcessed++` in one small transaction per song via
 * `JobProgressWriter`. F-04/F-05/F-09/F-10 are handled here (spec 14 §5).
 *
 * `PreferenceRecorder` is consulted for every CHOICE-band song, in **both modes** — deliberately not
 * gated by `$job->getMode()` (docs/specs/2026-08-25-playlist-normal-mode.md, D-188/AC-7.2): a
 * preference is a per-user override of matching itself, harmless and beneficial regardless of which
 * mode produced it, and consulting it unconditionally is what keeps this file free of the
 * `JobMode::Normal` branch the static test (AC-7.2) forbids outside `SetlistSelectionStage` and
 * `ReviewStage`.
 *
 * `run()` opens with `StalenessReconciler::reconcileResume()` (AC-8.1/AC-8.3) — same reasoning as
 * `PreferenceRecorder` above: staleness is a resume-time fact about the job, not a mode, so it is
 * consulted unconditionally rather than gated on `JobMode::Normal`.
 */
final readonly class MatchingStage
{
    public function __construct(
        private TrackMatcher $trackMatcher,
        private JobProgressWriter $progressWriter,
        private PreferenceRecorder $preferenceRecorder,
        private StalenessReconciler $stalenessReconciler,
        private ClockInterface $clock,
        private int $rateLimitInlineRetries,
    ) {
    }

    /**
     * @return array<int, list<array<string, mixed>>> the persisted candidates digest for every song
     *                                                 still in the CHOICE band (i.e. NOT resolved by
     *                                                 a preference), keyed by `PlaylistTrack::$ordinal`
     *                                                 — `ReviewStage`'s only source for `pendingChoices`
     *                                                 (spec 13 §6, D-200): no second search is ever
     *                                                 issued to rebuild it.
     */
    public function run(PlaylistGenerationJob $job, Playlist $playlist, StreamingProviderInterface $provider, ProviderTokens $tokens): array
    {
        // AC-8.1/AC-8.3: every attempt — first pass or resumed — reconciles staleness rows 1, 2 and 6
        // of spec 13 §6's table BEFORE any song below is (re-)matched. A no-op on a fresh first pass.
        $this->stalenessReconciler->reconcileResume($job, $playlist, \DateTimeImmutable::createFromInterface($this->clock->now()));

        /** @var list<PlaylistTrack> $tracks */
        $tracks = array_values($playlist->getTracks()->toArray());

        // Group rows by source song id — a medley's segments share one song and are resolved by a
        // single TrackMatcher::match() call, which itself performs the split (spec 12 §5).
        $bySong = [];
        foreach ($tracks as $track) {
            $song = $track->getSourceSong();
            if (null === $song || TrackOutcome::Pending !== $track->getOutcome()) {
                continue; // Already resolved by a prior attempt (idempotent resume).
            }
            $bySong[$song->getId()][] = $track;
        }

        $boundaries = self::computeSetBoundaries($tracks);

        /** @var array<int, list<array<string, mixed>>> $digestsByOrdinal */
        $digestsByOrdinal = [];

        foreach ($bySong as $rows) {
            /** @var list<PlaylistTrack> $rows */
            $first = $rows[0];
            $song = $first->getSourceSong();
            \assert(null !== $song);

            $isSetBoundary = $boundaries[$song->getId()] ?? false;

            $results = $this->matchWithInlineRetry($song, $first->getSourceBand()->getName(), $isSetBoundary, $provider, $tokens);

            foreach ($rows as $index => $row) {
                $result = $results[$index] ?? end($results);
                \assert($result instanceof MatchResult);
                $digest = $this->writeResult($job, $row, $result);
                if ([] !== $digest) {
                    $digestsByOrdinal[$row->getOrdinal()] = $digest;
                }
            }
        }

        return $digestsByOrdinal;
    }

    /** @return list<MatchResult> */
    private function matchWithInlineRetry(Song $song, string $bandName, bool $isSetBoundary, StreamingProviderInterface $provider, ProviderTokens $tokens): array
    {
        $attempts = 0;

        while (true) {
            try {
                return $this->trackMatcher->match($song, $bandName, $isSetBoundary, $provider, $tokens);
            } catch (QuotaExhaustedException $e) {
                throw new GenerationBlockedException('Provider quota exhausted during matching.', BlockedReason::ProviderQuota, null, PipelineStage::Matching, $e);
            } catch (RateLimitedException $e) {
                ++$attempts;
                if ($attempts > $this->rateLimitInlineRetries) {
                    $resumableAfter = \DateTimeImmutable::createFromInterface($this->clock->now())->modify('+15 minutes');

                    throw new GenerationBlockedException('Provider rate limit exceeded inline retries during matching.', BlockedReason::ProviderRateLimit, $resumableAfter, PipelineStage::Matching, $e);
                }
                usleep((int) (min($e->retryAfterSeconds ?? 1, 30) * 1_000_000));
            }
        }
    }

    /**
     * @return list<array<string, mixed>> the digest to carry into `pendingChoices` if this song is
     *                                    still a decision after the preference check — empty when it
     *                                    is not (auto-resolved, or not a CHOICE-band song at all)
     */
    private function writeResult(PlaylistGenerationJob $job, PlaylistTrack $track, MatchResult $result): array
    {
        [$outcome, $reasonCode, $reasonParams] = self::toTrackOutcome($result);
        $providerTrackId = $result->providerTrackId;
        $confidence = MatchOutcome::Skipped === $result->outcome ? null : $result->confidence;
        $digestForReview = [];

        if (TrackOutcome::MatchedLowConfidence === $outcome) {
            $candidateIds = array_values(array_filter(array_map(
                static fn (array $entry): ?string => \is_string($entry['providerTrackId'] ?? null) ? $entry['providerTrackId'] : null,
                $result->candidatesDigest,
            )));

            $preference = $this->preferenceRecorder->findApplicable(
                $job->getOwner(),
                $job->getProviderKey(),
                $job->getAlgorithmVersion(),
                $result->normalizedArtist,
                $result->normalizedTitle,
                $candidateIds,
            );

            if (null !== $preference) {
                // AC-5.2/AC-5.3: resolved, never a decision, but never silent either.
                $outcome = TrackOutcome::Matched;
                $providerTrackId = $preference->getProviderTrackId();
                $reasonCode = ReportCode::UsedYourPreviousChoice;
                $reasonParams = [];
                $this->preferenceRecorder->markUsed($preference);
            } else {
                $digestForReview = $result->candidatesDigest;
            }
        }

        $this->progressWriter->recordSongResolution($job, $track, $outcome, $providerTrackId, $confidence, $reasonCode, $reasonParams);

        return $digestForReview;
    }

    /** @return array{0: TrackOutcome, 1: ?ReportCode, 2: ?array<string, mixed>} */
    private static function toTrackOutcome(MatchResult $result): array
    {
        if (MatchOutcome::Skipped === $result->outcome) {
            return [TrackOutcome::Skipped, null, null];
        }

        if (MatchOutcome::NotFound === $result->outcome) {
            return [TrackOutcome::NotFound, ReportCode::TrackNotInCatalog, []];
        }

        $outcome = MatchOutcome::Matched === $result->outcome ? TrackOutcome::Matched : TrackOutcome::MatchedLowConfidence;

        if ($result->isCover) {
            return [$outcome, ReportCode::CoverOf, ['artist' => $result->coverArtist]];
        }

        if ($result->liveVersionOnly) {
            return [$outcome, ReportCode::LiveVersionOnly, []];
        }

        if (TrackOutcome::MatchedLowConfidence === $outcome) {
            return [$outcome, ReportCode::LowConfidenceMatch, []];
        }

        return [$outcome, null, null];
    }

    /**
     * @param list<PlaylistTrack> $tracks
     *
     * @return array<int, bool> songId => is first/last within its set
     */
    private static function computeSetBoundaries(array $tracks): array
    {
        /** @var array<int, Setlist> $setlistsBySongId */
        $setlistsBySongId = [];
        foreach ($tracks as $track) {
            $song = $track->getSourceSong();
            if (null !== $song) {
                $setlistsBySongId[$song->getId()] = $song->getSetlist();
            }
        }

        /** @var array<int, true> $seenSetlists */
        $seenSetlists = [];
        /** @var array<int, bool> $boundaries */
        $boundaries = [];
        foreach ($setlistsBySongId as $setlist) {
            $setlistId = $setlist->getId();
            if (null === $setlistId || isset($seenSetlists[$setlistId])) {
                continue;
            }
            $seenSetlists[$setlistId] = true;

            $bySetLabel = [];
            foreach ($setlist->getSongs() as $song) {
                $bySetLabel[$song->getSetLabel() ?? ''][] = $song;
            }

            foreach ($bySetLabel as $songsInSet) {
                $positions = array_map(static fn (Song $s): int => $s->getPosition(), $songsInSet);
                $min = min($positions);
                $max = max($positions);
                foreach ($songsInSet as $song) {
                    $songId = $song->getId();
                    if (null !== $songId) {
                        $boundaries[$songId] = $song->getPosition() === $min || $song->getPosition() === $max;
                    }
                }
            }
        }

        return $boundaries;
    }
}
