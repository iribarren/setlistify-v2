<?php

declare(strict_types=1);

namespace App\State\Setlist;

use App\ApiResource\Setlist\BandSearchCandidateOutput;
use App\ApiResource\Setlist\BandSetlistRefreshOutput;
use App\ApiResource\Setlist\FreshnessEnvelope;
use App\Entity\Band;
use App\Service\Setlist\ArtistSearchCandidate;
use App\Service\Setlist\SetlistRefreshRecord;

/**
 * `BandSetlistRefreshOutput` construction (docs/specs/2026-08-27-instant-setlist-refresh.md,
 * AC-3.4, AC-3.6). Kept out of the processors/provider themselves so the shape is built in exactly
 * one place — mirrors `PlaylistGenerationJobOutputMapper`'s role for the playlist pipeline.
 */
final readonly class BandSetlistRefreshOutputMapper
{
    public function fromRecord(Band $band, ?SetlistRefreshRecord $record): BandSetlistRefreshOutput
    {
        if (null === $record) {
            // AC-3.6: never refreshed — 200, state: null, the band's current resolution state.
            return new BandSetlistRefreshOutput(
                bandId: $band->getId() ?? 0,
                state: null,
                requestedAt: null,
                finishedAt: null,
                bandResolutionStateBefore: $band->getSetlistfmResolutionState(),
                bandResolutionStateAfter: null,
                freshness: FreshnessEnvelope::fresh('cache', null),
                cooldownUntil: null,
                candidates: [],
                refusedReason: null,
                retryAfterAt: null,
            );
        }

        return new BandSetlistRefreshOutput(
            bandId: $record->bandId,
            state: $record->state,
            requestedAt: $record->requestedAt,
            finishedAt: $record->finishedAt,
            bandResolutionStateBefore: $record->bandStateBefore,
            bandResolutionStateAfter: $record->bandStateAfter,
            freshness: null !== $record->freshnessSource
                ? ($record->freshnessStale
                    ? FreshnessEnvelope::degraded($record->freshnessSource, $record->freshnessFetchedAt, $record->freshnessReason ?? 'upstream_unavailable', $record->freshnessBudgetResetAt)
                    : FreshnessEnvelope::fresh($record->freshnessSource, $record->freshnessFetchedAt))
                : FreshnessEnvelope::fresh('cache', null),
            cooldownUntil: $record->cooldownUntil,
            candidates: self::mapCandidates($record->candidates),
            refusedReason: null,
            retryAfterAt: null,
        );
    }

    /** @param 'cooldown_active'|'daily_limit_reached'|'budget_reserved'|'budget_exhausted'|'rate_limited'|'upstream_unavailable' $reason */
    public function refused(Band $band, string $reason, \DateTimeImmutable $retryAfterAt): BandSetlistRefreshOutput
    {
        return new BandSetlistRefreshOutput(
            bandId: $band->getId() ?? 0,
            state: null,
            requestedAt: null,
            finishedAt: null,
            bandResolutionStateBefore: $band->getSetlistfmResolutionState(),
            bandResolutionStateAfter: null,
            freshness: FreshnessEnvelope::fresh('cache', null),
            cooldownUntil: null,
            candidates: [],
            refusedReason: $reason,
            retryAfterAt: $retryAfterAt,
        );
    }

    public static function retryAfterSeconds(?SetlistRefreshRecord $record, \DateTimeImmutable $now): ?int
    {
        if (null === $record || !$record->isActive()) {
            return null;
        }

        return 2; // AC-3.5: a short, fixed poll interval while queued/running — a 1-3s operation.
    }

    /**
     * @param list<ArtistSearchCandidate> $candidates
     *
     * @return list<BandSearchCandidateOutput>
     */
    private static function mapCandidates(array $candidates): array
    {
        return array_map(
            static fn (ArtistSearchCandidate $c): BandSearchCandidateOutput => new BandSearchCandidateOutput($c->mbid, $c->name, $c->sortName, $c->disambiguation),
            $candidates,
        );
    }
}
