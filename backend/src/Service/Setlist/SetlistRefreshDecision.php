<?php

declare(strict_types=1);

namespace App\Service\Setlist;

/**
 * The result of asking `SetlistRefreshCoordinator` whether a trigger (or a pick's completion) may
 * proceed (docs/specs/2026-08-27-instant-setlist-refresh.md, D-259, D-262).
 */
final readonly class SetlistRefreshDecision
{
    /** @param 'accepted'|'alreadyInFlight'|'refused' $kind */
    private function __construct(
        public string $kind,
        public ?SetlistRefreshRecord $record,
        /** @var 'cooldown_active'|'daily_limit_reached'|'budget_reserved'|'budget_exhausted'|'rate_limited'|'upstream_unavailable'|null */
        public ?string $refusedReason,
        public ?\DateTimeImmutable $retryAfterAt,
    ) {
    }

    public static function accepted(SetlistRefreshRecord $record): self
    {
        return new self('accepted', $record, null, null);
    }

    /** D-262: a second trigger for an already-in-flight band returns the existing record, never a refusal. */
    public static function alreadyInFlight(SetlistRefreshRecord $record): self
    {
        return new self('alreadyInFlight', $record, null, null);
    }

    /** @param 'cooldown_active'|'daily_limit_reached'|'budget_reserved'|'budget_exhausted'|'rate_limited'|'upstream_unavailable' $reason */
    public static function refused(string $reason, \DateTimeImmutable $retryAfterAt): self
    {
        return new self('refused', null, $reason, $retryAfterAt);
    }
}
