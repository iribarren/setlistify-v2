<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

/** One source song's row in the report (spec 14 §6) — including the ones that produced no track. */
final readonly class PlaylistTrackOutput
{
    /** @param array<string, mixed>|null $reasonParams */
    public function __construct(
        public int $ordinal,
        public int $sourcePosition,
        public ?int $segmentIndex,
        public string $bandName,
        public string $sourceTitle,
        public ?string $providerTrackId,
        public ?float $confidence,
        public string $outcome,
        public ?string $reasonCode,
        public ?array $reasonParams,
    ) {
    }
}
