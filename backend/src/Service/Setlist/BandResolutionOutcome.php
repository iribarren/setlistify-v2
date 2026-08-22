<?php

declare(strict_types=1);

namespace App\Service\Setlist;

use App\Entity\Band;

/** The result of {@see BandIdentityResolver::ensureResolved()} (US-1, US-2, US-5). */
final readonly class BandResolutionOutcome
{
    private function __construct(
        public Band $band,
        /** One of the `Band::RESOLUTION_*` constants. */
        public string $state,
        /** @var list<ArtistSearchCandidate> */
        public array $candidates,
        /** @var 'budget_exhausted'|'rate_limited'|'upstream_unavailable'|null */
        public ?string $unavailableReason,
        public ?\DateTimeImmutable $budgetResetAt,
    ) {
    }

    public static function resolved(Band $band): self
    {
        return new self($band, Band::RESOLUTION_RESOLVED, [], null, null);
    }

    public static function noPresence(Band $band): self
    {
        return new self($band, Band::RESOLUTION_NO_PRESENCE, [], null, null);
    }

    /** @param list<ArtistSearchCandidate> $candidates */
    public static function ambiguous(Band $band, array $candidates): self
    {
        return new self($band, Band::RESOLUTION_AMBIGUOUS, $candidates, null, null);
    }

    /** The band's persisted state, without a fresh search (already ambiguous/no_presence). */
    public static function fromState(Band $band): self
    {
        return new self($band, $band->getSetlistfmResolutionState(), [], null, null);
    }

    /**
     * AC-5.5: distinguishable, by field value alone, from a `no_presence` band.
     *
     * @param 'budget_exhausted'|'rate_limited'|'upstream_unavailable'|null $reason
     */
    public static function unavailable(Band $band, ?string $reason, ?\DateTimeImmutable $budgetResetAt): self
    {
        return new self($band, 'unresolved', [], $reason, $budgetResetAt);
    }
}
