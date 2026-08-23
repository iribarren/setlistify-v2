<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\Playlist\PlaylistOutput;
use App\ApiResource\Playlist\PlaylistTrackOutput;
use App\ApiResource\Playlist\ReportEntryOutput;
use App\Entity\Playlist;
use App\Entity\PlaylistTrack;
use App\Service\Playlist\Model\TrackOutcome;

/** `Playlist` entity -> `PlaylistOutput` DTO (spec 14 §6). Carries no provider token or raw digest. */
final readonly class PlaylistOutputMapper
{
    public function map(Playlist $playlist): PlaylistOutput
    {
        $playlistId = $playlist->getId() ?? throw new \LogicException('Playlist has no id yet — not persisted.');

        $report = array_values(array_map(
            static fn (array $entry): ReportEntryOutput => new ReportEntryOutput($entry['code'], $entry['params']),
            $playlist->getReportSummary(),
        ));

        $tracks = [];
        $hits = 0;
        $denominator = 0;
        foreach ($playlist->getTracks() as $track) {
            /** @var PlaylistTrack $track */
            $tracks[] = new PlaylistTrackOutput(
                ordinal: $track->getOrdinal(),
                sourcePosition: $track->getSourcePosition(),
                segmentIndex: $track->getSegmentIndex(),
                bandName: $track->getSourceBand()->getName(),
                sourceTitle: $track->getSourceTitle(),
                providerTrackId: $track->getProviderTrackId(),
                confidence: $track->getConfidence(),
                outcome: $track->getOutcome()->value,
                reasonCode: $track->getReasonCode()?->value,
                reasonParams: $track->getReasonParams(),
            );

            if (TrackOutcome::Skipped !== $track->getOutcome() && TrackOutcome::Pending !== $track->getOutcome()) {
                ++$denominator;
                if ($track->getOutcome()->isHit()) {
                    ++$hits;
                }
            }
        }

        $matchRate = $denominator > 0 ? $hits / $denominator : 0.0;

        return new PlaylistOutput(
            id: $playlistId,
            concertId: $playlist->getConcert()->getId() ?? 0,
            provider: $playlist->getProviderKey(),
            name: $playlist->getName(),
            description: $playlist->getDescription(),
            externalUrl: $playlist->getExternalUrl(),
            resultKind: $playlist->getJob()->getResultKind()?->value,
            matchRate: $matchRate,
            createdAt: $playlist->getCreatedAt(),
            report: $report,
            tracks: $tracks,
        );
    }
}
