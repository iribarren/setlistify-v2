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
 */
final readonly class MatchingStage
{
    public function __construct(
        private TrackMatcher $trackMatcher,
        private JobProgressWriter $progressWriter,
        private ClockInterface $clock,
        private int $rateLimitInlineRetries,
    ) {
    }

    public function run(PlaylistGenerationJob $job, Playlist $playlist, StreamingProviderInterface $provider, ProviderTokens $tokens): void
    {
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
                $this->writeResult($job, $row, $result);
            }
        }
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

    private function writeResult(PlaylistGenerationJob $job, PlaylistTrack $track, MatchResult $result): void
    {
        [$outcome, $reasonCode, $reasonParams] = self::toTrackOutcome($result);

        $this->progressWriter->recordSongResolution($job, $track, $outcome, $result->providerTrackId, MatchOutcome::Skipped === $result->outcome ? null : $result->confidence, $reasonCode, $reasonParams);
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
