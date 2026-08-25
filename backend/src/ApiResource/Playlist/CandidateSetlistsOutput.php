<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

/**
 * `GET /api/playlist-generation-jobs/{id}/candidate-setlists` (T-04, docs/specs/
 * 2026-08-25-playlist-normal-mode.md). A projection of `PlaylistGenerationJob::$candidateSetlists`,
 * nothing re-derived. `bands` is ordered `billingOrder` ASC — headliner first (D-25).
 */
final readonly class CandidateSetlistsOutput
{
    /** @param list<CandidateSetlistBandOutput> $bands */
    public function __construct(
        public int $jobId,
        public \DateTimeImmutable $expiresAt,
        public int $concertId,
        public array $bands,
    ) {
    }
}
