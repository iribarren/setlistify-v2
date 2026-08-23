<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

/**
 * `GET /api/playlists/{id}` (spec 14 §6). Carries no provider token, no raw candidate payload, no
 * `candidatesDigest` — those are backoffice and harness data.
 */
final readonly class PlaylistOutput
{
    /**
     * @param list<ReportEntryOutput>  $report
     * @param list<PlaylistTrackOutput> $tracks
     */
    public function __construct(
        public int $id,
        public int $concertId,
        public string $provider,
        public string $name,
        public ?string $description,
        public ?string $externalUrl,
        public ?string $resultKind,
        public float $matchRate,
        public \DateTimeImmutable $createdAt,
        public array $report,
        public array $tracks,
    ) {
    }
}
