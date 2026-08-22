<?php

declare(strict_types=1);

namespace App\Service\Setlist;

/**
 * The outcome of one {@see SetlistFmClient::request()} call. `notFound` and `success` are both
 * "the request was issued and answered"; `degraded` means no usable answer came back (budget/rate
 * refusal, or retries exhausted against a transient failure) and the caller must fall back to
 * whatever is already cached (D-63).
 */
final readonly class SetlistFmClientResult
{
    private function __construct(
        public bool $success,
        public bool $notFound,
        public bool $degraded,
        /** @var array<string, mixed>|null */
        public ?array $payload,
        public ?int $httpStatus,
        /** @var 'budget_exhausted'|'rate_limited'|'upstream_unavailable'|null */
        public ?string $degradedReason,
        public ?\DateTimeImmutable $budgetResetAt,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function success(array $payload, int $httpStatus): self
    {
        return new self(true, false, false, $payload, $httpStatus, null, null);
    }

    public static function notFound(): self
    {
        return new self(false, true, false, null, 404, null, null);
    }

    /** A non-retried 4xx other than 404 (AC-9.2) — never re-thrown to the client verbatim (AC-9.7). */
    public static function clientError(int $httpStatus): self
    {
        return new self(false, false, true, null, $httpStatus, 'upstream_unavailable', null);
    }

    /** @param 'budget_exhausted'|'rate_limited'|'upstream_unavailable' $reason */
    public static function degraded(string $reason, ?\DateTimeImmutable $budgetResetAt): self
    {
        return new self(false, false, true, null, null, $reason, $budgetResetAt);
    }
}
