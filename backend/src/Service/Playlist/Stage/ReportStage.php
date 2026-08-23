<?php

declare(strict_types=1);

namespace App\Service\Playlist\Stage;

use App\Entity\Playlist;
use App\Entity\PlaylistGenerationJob;
use App\Entity\PlaylistTrack;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\PipelineStage;
use App\Service\Playlist\Model\ResultKind;
use App\Service\Playlist\Model\TrackOutcome;
use Psr\Clock\ClockInterface;

/**
 * Freezes the six outcome counters, `meanConfidence`, `durationMs` (excluding `blockedMs`),
 * `stageTimings` and `resultKind`, then moves the job to `completed` (spec 13 §8, spec 14 §4).
 */
final readonly class ReportStage
{
    public function __construct(
        private JobStateMachine $stateMachine,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, int> $stageTimings */
    public function run(PlaylistGenerationJob $job, Playlist $playlist, array $stageTimings): void
    {
        $job->enterStage(PipelineStage::Report, \DateTimeImmutable::createFromInterface($this->clock->now()));

        $tracks = $playlist->getTracks();

        $matched = 0;
        $lowConfidence = 0;
        $notFound = 0;
        $skipped = 0;
        $regionRestricted = 0;
        $confidenceSum = 0.0;
        $confidenceCount = 0;

        foreach ($tracks as $track) {
            /** @var PlaylistTrack $track */
            match ($track->getOutcome()) {
                TrackOutcome::Matched => $matched++,
                TrackOutcome::MatchedLowConfidence => $lowConfidence++,
                TrackOutcome::NotFound => $notFound++,
                TrackOutcome::Skipped => $skipped++,
                TrackOutcome::RegionRestricted => $regionRestricted++,
                TrackOutcome::Pending => null,
            };

            if ($track->getOutcome()->isHit() && null !== $track->getConfidence()) {
                $confidenceSum += $track->getConfidence();
                ++$confidenceCount;
            }
        }

        $meanConfidence = $confidenceCount > 0 ? $confidenceSum / $confidenceCount : null;

        $startedAt = $job->getStartedAt();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $durationMs = null !== $startedAt
            ? max(0, ($now->getTimestamp() - $startedAt->getTimestamp()) * 1000 - $job->getBlockedMs())
            : null;

        $resultKind = $matched + $lowConfidence === 0
            ? ResultKind::NoTracksMatched
            : ($notFound + $skipped + $regionRestricted > 0 ? ResultKind::Partial : ResultKind::Complete);

        $job->freezeCounters($matched, $lowConfidence, $notFound, $skipped, $regionRestricted, $meanConfidence, $durationMs, $stageTimings, $resultKind, $now);
        $this->stateMachine->complete($job);
    }
}
