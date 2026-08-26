<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * The shape of a review as read (US-2, US-5). `highlightTitle` is always populated when a highlight
 * exists; `highlightSongId` is `null` for a freely-typed highlight (D-232) or when the source `Song`
 * row was later removed by a setlist refresh (`ON DELETE SET NULL`, AC-5.4) — the client never needs
 * to dereference it to render.
 */
final readonly class ConcertReviewOutput
{
    public function __construct(
        public ?int $rating,
        public ?string $notes,
        public ?int $highlightSongId,
        public ?string $highlightTitle,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
