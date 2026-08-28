<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

/**
 * `GET`/`POST …/setlist-refresh` and `POST …/setlist-refresh/resolution`
 * (docs/specs/2026-08-27-instant-setlist-refresh.md, AC-1.1, AC-3.4). One output shape for the
 * whole polling contract, mirroring `PlaylistGenerationJobOutput`'s precedent.
 *
 * `refusedReason`/`retryAfterAt` are populated ONLY on a throttle refusal (`429`) — `FreshnessEnvelope`
 * itself is never extended with throttle reasons (D-261).
 */
final readonly class BandSetlistRefreshOutput
{
    public function __construct(
        public int $bandId,
        /** @var 'queued'|'running'|'succeeded'|'failed'|null null when this band has never been refreshed (AC-3.6) */
        public ?string $state,
        public ?\DateTimeImmutable $requestedAt,
        public ?\DateTimeImmutable $finishedAt,
        /** One of `Band::RESOLUTION_*` — the band's resolution state before this refresh ran. */
        public string $bandResolutionStateBefore,
        /** One of `Band::RESOLUTION_*`, or `null` while not yet terminal. */
        public ?string $bandResolutionStateAfter,
        public FreshnessEnvelope $freshness,
        public ?\DateTimeImmutable $cooldownUntil,
        /** @var list<BandSearchCandidateOutput> AC-6.2: present when the outcome is `ambiguous`. */
        public array $candidates,
        /** @var 'cooldown_active'|'daily_limit_reached'|'budget_reserved'|'budget_exhausted'|'rate_limited'|'upstream_unavailable'|null */
        public ?string $refusedReason,
        public ?\DateTimeImmutable $retryAfterAt,
    ) {
    }
}
