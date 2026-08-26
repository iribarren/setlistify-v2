<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\ConcertReviewOutput;
use App\ApiResource\ConcertReviewSummaryOutput;
use App\Entity\ConcertReview;

/**
 * `ConcertReview` entity → `ConcertReviewOutput`/`ConcertReviewSummaryOutput` DTOs (D-29), matching
 * `App\State\ConcertOutputMapper`'s role for `Concert`.
 */
final readonly class ConcertReviewOutputMapper
{
    public function map(ConcertReview $review): ConcertReviewOutput
    {
        return new ConcertReviewOutput(
            rating: $review->getRating(),
            notes: $review->getNotes(),
            highlightSongId: $review->getHighlightSong()?->getId(),
            highlightTitle: $review->getHighlightTitle(),
            createdAt: $review->getCreatedAt(),
            updatedAt: $review->getUpdatedAt(),
        );
    }

    /** AC-6.1/AC-6.2: the list indicator — never the notes body. */
    public function mapSummary(ConcertReview $review): ConcertReviewSummaryOutput
    {
        return new ConcertReviewSummaryOutput(
            rating: $review->getRating(),
            highlightTitle: $review->getHighlightTitle(),
            updatedAt: $review->getUpdatedAt(),
        );
    }
}
