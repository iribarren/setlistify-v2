<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\FailureReason;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\NoSetlistCause;
use App\Service\Playlist\Model\ResultKind;

/**
 * Deliberately flat (spec 14 §6) — polled up to ~20 times per generation. `playlistId` is null until
 * a `Playlist` row exists (matching started producing output); the various reason fields are null
 * except in their own state.
 */
final readonly class PlaylistGenerationJobOutput
{
    public function __construct(
        public int $id,
        public int $concertId,
        public string $provider,
        /** @var 'fast'|'normal' */
        public string $mode,
        public JobState $state,
        public ?string $currentStage,
        public int $songsTotal,
        public int $songsProcessed,
        public ?int $estimatedSecondsRemaining,
        public ?BlockedReason $blockedReason,
        public ?\DateTimeImmutable $resumableAfter,
        public ?FailureReason $failureReason,
        public ?ResultKind $resultKind,
        /** Non-null only when `resultKind === ResultKind::NoSourceMaterial` (D-184). */
        public ?NoSetlistCause $noSetlistCause,
        public ?int $playlistId,
        public int $matchedCount,
        public int $lowConfidenceCount,
        public int $notFoundCount,
        public int $skippedCount,
        public int $regionRestrictedCount,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        /** D-209/AC-9.1: null until the job's version step has been reached at least once. */
        public ?int $choicesRequiredCount = null,
        public ?int $choicesMadeCount = null,
    ) {
    }
}
