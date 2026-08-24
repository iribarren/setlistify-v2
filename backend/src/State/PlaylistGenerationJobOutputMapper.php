<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\Playlist\PlaylistGenerationJobOutput;
use App\Entity\PlaylistGenerationJob;
use App\Repository\PlaylistRepository;
use App\Service\Playlist\GenerationEstimator;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ResultKind;
use App\Service\Playlist\NoSetlistCauseFolder;

/** `PlaylistGenerationJob` entity -> `PlaylistGenerationJobOutput` DTO (spec 14 §6). */
final readonly class PlaylistGenerationJobOutputMapper
{
    public function __construct(
        private PlaylistRepository $playlistRepository,
        private GenerationEstimator $estimator,
        private NoSetlistCauseFolder $noSetlistCauseFolder,
    ) {
    }

    public function map(PlaylistGenerationJob $job): PlaylistGenerationJobOutput
    {
        $jobId = $job->getId() ?? throw new \LogicException('PlaylistGenerationJob has no id yet — not persisted.');

        $estimatedSecondsRemaining = $job->getState()->isActive()
            ? $this->estimator->estimateSecondsRemaining($job->getSongsProcessed(), $job->getSongsTotal(), [])
            : null;

        $playlist = $this->playlistRepository->findOneBy(['job' => $job]);

        $noSetlistCause = ResultKind::NoSourceMaterial === $job->getResultKind() && null !== $playlist
            ? $this->noSetlistCauseFolder->fold($playlist->getReportSummary())
            : null;

        return new PlaylistGenerationJobOutput(
            id: $jobId,
            concertId: $job->getConcert()->getId() ?? 0,
            provider: $job->getProviderKey(),
            mode: $job->getMode()->value,
            state: $job->getState(),
            currentStage: $job->getCurrentStage()?->value,
            songsTotal: $job->getSongsTotal(),
            songsProcessed: $job->getSongsProcessed(),
            estimatedSecondsRemaining: $estimatedSecondsRemaining,
            blockedReason: $job->getBlockedReason(),
            resumableAfter: $job->getResumableAfter(),
            failureReason: $job->getFailureReason(),
            resultKind: $job->getResultKind(),
            noSetlistCause: $noSetlistCause,
            playlistId: $playlist?->getId(),
            matchedCount: $job->getMatchedCount(),
            lowConfidenceCount: $job->getLowConfidenceCount(),
            notFoundCount: $job->getNotFoundCount(),
            skippedCount: $job->getSkippedCount(),
            regionRestrictedCount: $job->getRegionRestrictedCount(),
            createdAt: $job->getCreatedAt(),
            startedAt: $job->getStartedAt(),
            finishedAt: $job->getFinishedAt(),
        );
    }

    /** D-150: Retry-After per state, absent on a terminal, blocked or suspended state. */
    public static function retryAfterSeconds(JobState $state): ?int
    {
        return match ($state) {
            JobState::Matching, JobState::Building => 1,
            JobState::Queued, JobState::ResolvingSetlist => 3,
            default => null,
        };
    }

    /** D-150: `W/"<id>-<state>-<songsProcessed>-<updatedAt epoch>"`. */
    public static function etag(PlaylistGenerationJob $job): string
    {
        return \sprintf(
            'W/"%d-%s-%d-%d"',
            $job->getId() ?? 0,
            $job->getState()->value,
            $job->getSongsProcessed(),
            $job->getUpdatedAt()->getTimestamp(),
        );
    }
}
