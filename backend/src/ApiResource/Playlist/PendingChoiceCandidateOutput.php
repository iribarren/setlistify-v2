<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

/**
 * `label` is a closed vocabulary (`top_pick`|`only_match`|`alternative`|`your_previous_choice`) —
 * **a raw confidence score never appears here** (D-204, AC-2.5).
 */
final readonly class PendingChoiceCandidateOutput
{
    public function __construct(
        public string $providerTrackId,
        public ?string $title,
        public ?string $artistName,
        public ?string $albumName,
        public ?int $releaseYear,
        public ?int $durationMs,
        public string $label,
    ) {
    }
}
