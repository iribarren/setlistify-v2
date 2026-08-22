<?php

declare(strict_types=1);

namespace App\Service\Setlist;

/**
 * The outcome of {@see SetlistFmBudget::acquire()} — a typed refusal reason that flows straight
 * into the freshness envelope (D-61, AC-8.3), rather than a caller having to infer why a request
 * couldn't be made.
 */
final readonly class SetlistFmBudgetDecision
{
    private function __construct(
        public bool $allowed,
        /** @var 'budget_exhausted'|'rate_limited'|'upstream_unavailable'|null */
        public ?string $reason,
        public ?\DateTimeImmutable $resetAt,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null, null);
    }

    /** @param 'budget_exhausted'|'rate_limited'|'upstream_unavailable' $reason */
    public static function refuse(string $reason, ?\DateTimeImmutable $resetAt = null): self
    {
        return new self(false, $reason, $resetAt);
    }
}
