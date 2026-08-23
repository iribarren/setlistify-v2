<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

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
        public string $state,
        public ?string $currentStage,
        public int $songsTotal,
        public int $songsProcessed,
        public ?int $estimatedSecondsRemaining,
        public ?string $blockedReason,
        public ?\DateTimeImmutable $resumableAfter,
        public ?string $failureReason,
        public ?string $resultKind,
        public ?int $playlistId,
        public int $matchedCount,
        public int $lowConfidenceCount,
        public int $notFoundCount,
        public int $skippedCount,
        public int $regionRestrictedCount,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
    ) {
    }
}
