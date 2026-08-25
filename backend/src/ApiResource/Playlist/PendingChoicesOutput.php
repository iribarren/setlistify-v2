<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

/**
 * `GET /api/playlist-generation-jobs/{id}/pending-choices` (T-07, docs/specs/
 * 2026-08-25-playlist-normal-mode.md). A projection of `PlaylistGenerationJob::$pendingChoices`,
 * nothing re-derived. **No raw confidence number appears anywhere in this tree** (D-204, AC-2.5).
 */
final readonly class PendingChoicesOutput
{
    /**
     * @param list<PendingChoiceAutoResolvedOutput> $autoResolved
     * @param list<PendingChoiceDecisionOutput>     $decisions
     */
    public function __construct(
        public int $jobId,
        public \DateTimeImmutable $expiresAt,
        public int $songsTotal,
        public int $autoResolvedCount,
        public int $choicesRequiredCount,
        public array $autoResolved,
        public array $decisions,
    ) {
    }
}
