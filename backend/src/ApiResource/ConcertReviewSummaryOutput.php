<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * The diary indicator carried by `ConcertOutput.reviewSummary` (D-241, AC-6.1). Deliberately does
 * NOT carry the notes body (AC-6.2) — the list does not need it, and personal writing should live
 * in as few response caches as possible. The full body comes from `ConcertReviewOutput`, fetched by
 * the concert page that actually displays it.
 */
final readonly class ConcertReviewSummaryOutput
{
    public function __construct(
        public ?int $rating,
        public ?string $highlightTitle,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
