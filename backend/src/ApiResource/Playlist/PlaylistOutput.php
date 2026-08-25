<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use App\Service\Playlist\Model\NoSetlistCause;
use App\Service\Playlist\Model\ResultKind;

/**
 * `GET /api/playlists/{id}` (spec 14 §6). Carries no provider token, no raw candidate payload, no
 * `candidatesDigest` — those are backoffice and harness data.
 */
final readonly class PlaylistOutput
{
    /**
     * @param list<ReportEntryOutput>    $report
     * @param list<PlaylistTrackOutput>  $tracks
     * @param list<SourceSetlistOutput>  $sourceSetlists
     */
    public function __construct(
        public int $id,
        public int $concertId,
        public string $provider,
        public string $name,
        public ?string $description,
        public ?string $externalUrl,
        /**
         * The provider's embeddable player URL, or null when the provider offers none, the playlist
         * has no provider-side id yet, or the provider cannot be resolved (D-211).
         */
        public ?string $embedUrl,
        public ?ResultKind $resultKind,
        /** Non-null only when `resultKind === ResultKind::NoSourceMaterial` (D-184). */
        public ?NoSetlistCause $noSetlistCause,
        public float $matchRate,
        public \DateTimeImmutable $createdAt,
        public array $report,
        public array $tracks,
        /** One entry per distinct (sourceBand, sourceSetlistfmId), first-appearance order (D-185). */
        public array $sourceSetlists,
    ) {
    }
}
