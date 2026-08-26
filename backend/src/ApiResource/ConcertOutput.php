<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * The shape of a concert as read (AC-3.2). `status` is derived, never stored (D-24); `lineup` is
 * ordered by billing order ascending (AC-1.4). Omitted optional fields are `null`, never absent
 * (AC-2.7), so the generated client's types are stable.
 *
 * `note` is gone (D-239, docs/specs/2026-08-26-notes-and-reviews.md) — its content lives in
 * `ConcertReview` now. `reviewSummary` is the diary indicator (D-241): always present, `null` when
 * there is no review, and never carries the notes body (AC-6.1, AC-6.2) — that comes from
 * `GET /api/concerts/{concertId}/review`.
 */
final readonly class ConcertOutput
{
    /** @param list<LineupEntryOutput> $lineup */
    public function __construct(
        public int $id,
        public string $date,
        public string $timezone,
        /** @var 'upcoming'|'past' */
        public string $status,
        public array $lineup,
        public VenueData $venue,
        public ?MoneyData $ticketPrice,
        public ?string $doorsTime,
        public ?string $startTime,
        public ?ConcertReviewSummaryOutput $reviewSummary,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
