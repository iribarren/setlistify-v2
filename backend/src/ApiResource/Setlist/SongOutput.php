<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

/**
 * One song, in playing order (AC-4.1, AC-4.2, AC-4.3).
 *
 * `id` is the persisted `App\Entity\Song` row's identity — null when this song has not yet been
 * relationally persisted (the raw-payload fallback path in `SetlistDetailProvider` for a setlist
 * whose band has no local `Band` row to attach to). It exists so the review feature's structured
 * highlight (`ConcertReviewInput::$highlightSongId`, docs/specs/2026-08-26-notes-and-reviews.md
 * D-232/D-233) has a real id to submit — the field is never rendered, only submitted.
 */
final readonly class SongOutput
{
    public function __construct(
        public ?int $id,
        public int $position,
        public ?string $setLabel,
        public string $title,
        public ?string $coverOfName,
        public ?string $coverOfMbid,
        public ?string $withName,
        public ?string $info,
        public bool $isTape,
    ) {
    }
}
