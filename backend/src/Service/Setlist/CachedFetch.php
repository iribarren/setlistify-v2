<?php

declare(strict_types=1);

namespace App\Service\Setlist;

/**
 * The result of one {@see SetlistCache} tiered read — carries everything
 * `App\ApiResource\FreshnessEnvelope` needs (D-63, AC-8.3), plus the payload itself.
 */
final readonly class CachedFetch
{
    private function __construct(
        /** @var array<string, mixed>|null */
        public ?array $payload,
        public bool $notFound,
        /** @var 'live'|'cache' */
        public string $source,
        public ?\DateTimeImmutable $fetchedAt,
        public bool $stale,
        /** @var 'budget_exhausted'|'rate_limited'|'upstream_unavailable'|null */
        public ?string $reason,
        public ?\DateTimeImmutable $budgetResetAt,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function live(array $payload, \DateTimeImmutable $fetchedAt): self
    {
        return new self($payload, false, 'live', $fetchedAt, false, null, null);
    }

    /** @param array<string, mixed> $payload */
    public static function cacheHit(array $payload, \DateTimeImmutable $fetchedAt): self
    {
        return new self($payload, false, 'cache', $fetchedAt, false, null, null);
    }

    /**
     * @param array<string, mixed>                                     $payload
     * @param 'budget_exhausted'|'rate_limited'|'upstream_unavailable' $reason
     */
    public static function staleCache(array $payload, \DateTimeImmutable $fetchedAt, string $reason, ?\DateTimeImmutable $budgetResetAt): self
    {
        return new self($payload, false, 'cache', $fetchedAt, true, $reason, $budgetResetAt);
    }

    public static function notFoundResult(): self
    {
        return new self(null, true, 'live', null, false, null, null);
    }

    /**
     * AC-8.2: exhausted budget and nothing cached at all — still a 200, never an error.
     *
     * @param 'budget_exhausted'|'rate_limited'|'upstream_unavailable' $reason
     */
    public static function unavailable(string $reason, ?\DateTimeImmutable $budgetResetAt): self
    {
        return new self(null, false, 'cache', null, true, $reason, $budgetResetAt);
    }
}
