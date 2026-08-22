<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

/**
 * Carried on every setlist-bearing response (D-63, AC-8.3, AC-8.5). A degraded read is still a
 * `200` with an honest explanation — never a status code standing in for product meaning.
 */
final readonly class FreshnessEnvelope
{
    public function __construct(
        /** @var 'live'|'cache' */
        public string $source,
        public ?\DateTimeImmutable $fetchedAt,
        public bool $stale,
        /** @var 'budget_exhausted'|'rate_limited'|'upstream_unavailable'|null */
        public ?string $reason,
        /** AC-8.4: when the daily budget resets, so the client can say "tomorrow at …". */
        public ?\DateTimeImmutable $budgetResetAt,
    ) {
    }

    /** @param 'live'|'cache' $source */
    public static function fresh(string $source, ?\DateTimeImmutable $fetchedAt): self
    {
        return new self($source, $fetchedAt, false, null, null);
    }

    /**
     * @param 'live'|'cache'                                           $source
     * @param 'budget_exhausted'|'rate_limited'|'upstream_unavailable' $reason
     */
    public static function degraded(string $source, ?\DateTimeImmutable $fetchedAt, string $reason, ?\DateTimeImmutable $budgetResetAt): self
    {
        return new self($source, $fetchedAt, true, $reason, $budgetResetAt);
    }
}
