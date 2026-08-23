<?php

declare(strict_types=1);

namespace App\Service\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Entity\PlaylistTrack;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\TrackOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Writes one song's resolution and advances `songsProcessed`, both in one small, independent
 * transaction (spec 13 §7) — a worker killed mid-match leaves `songsProcessed` accurate, and the
 * backoffice can see exactly where a stuck job stopped.
 */
final readonly class JobProgressWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed>|null $reasonParams */
    public function recordSongResolution(
        PlaylistGenerationJob $job,
        PlaylistTrack $track,
        TrackOutcome $outcome,
        ?string $providerTrackId,
        ?float $confidence,
        ?ReportCode $reasonCode,
        ?array $reasonParams,
    ): void {
        $track->resolve($outcome, $providerTrackId, $confidence, $reasonCode, $reasonParams);
        $job->incrementSongsProcessed(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();
    }
}
