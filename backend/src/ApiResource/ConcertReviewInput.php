<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\Validator\ValidConcertReviewInput;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The entire request surface of `PUT /api/concerts/{concertId}/review` (D-228). No `concertId`
 * (it's in the path), no `owner` and no `id` exists to send (D-29's DTO discipline, matching
 * `ConcertInput`'s "no `owner`/`id`" shape).
 */
#[ValidConcertReviewInput]
final class ConcertReviewInput
{
    /** 1-5 inclusive (D-230). Nullable — a review may be notes-only (D-231). */
    #[Assert\Range(min: 1, max: 5)]
    public ?int $rating = null;

    /** Plain text, no rendering contract (D-237), ≤ 4000 graphemes so a family emoji costs 1, not 7 (D-236). */
    #[Assert\Length(max: 4000, countUnit: Assert\Length::COUNT_GRAPHEMES)]
    public ?string $notes = null;

    /** Must belong to a `Setlist`/`Song` of a band in this concert's lineup — checked by the processor (D-233). */
    public ?int $highlightSongId = null;

    /** The always-populated snapshot; the only thing ever rendered (D-232). */
    #[Assert\Length(max: 200, countUnit: Assert\Length::COUNT_GRAPHEMES)]
    public ?string $highlightTitle = null;
}
