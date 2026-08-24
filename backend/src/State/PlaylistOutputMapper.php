<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\Playlist\PlaylistOutput;
use App\ApiResource\Playlist\PlaylistTrackOutput;
use App\ApiResource\Playlist\ReportEntryOutput;
use App\ApiResource\Playlist\SourceSetlistOutput;
use App\Entity\Playlist;
use App\Entity\PlaylistTrack;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\ResultKind;
use App\Service\Playlist\Model\TrackOutcome;
use App\Service\Playlist\NoSetlistCauseFolder;

/** `Playlist` entity -> `PlaylistOutput` DTO (spec 14 §6). Carries no provider token or raw digest. */
final readonly class PlaylistOutputMapper
{
    public function __construct(
        private NoSetlistCauseFolder $noSetlistCauseFolder,
    ) {
    }

    public function map(Playlist $playlist): PlaylistOutput
    {
        $playlistId = $playlist->getId() ?? throw new \LogicException('Playlist has no id yet — not persisted.');

        $reportSummary = $playlist->getReportSummary();
        $report = array_values(array_map(
            static fn (array $entry): ReportEntryOutput => new ReportEntryOutput(ReportCode::from($entry['code']), $entry['params']),
            $reportSummary,
        ));

        $resultKind = $playlist->getJob()->getResultKind();
        $noSetlistCause = ResultKind::NoSourceMaterial === $resultKind
            ? $this->noSetlistCauseFolder->fold($reportSummary)
            : null;

        $tracks = [];
        $hits = 0;
        $denominator = 0;
        /** @var array<string, SourceSetlistOutput> $sourceSetlistsByKey */
        $sourceSetlistsByKey = [];
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
                outcome: $track->getOutcome(),
                reasonCode: $track->getReasonCode(),
                reasonParams: $track->getReasonParams(),
            );

            if (TrackOutcome::Skipped !== $track->getOutcome() && TrackOutcome::Pending !== $track->getOutcome()) {
                ++$denominator;
                if ($track->getOutcome()->isHit()) {
                    ++$hits;
                }
            }

            $sourceSetlistfmId = $track->getSourceSetlistfmId();
            $key = $track->getSourceBand()->getId().':'.$sourceSetlistfmId;
            if (!isset($sourceSetlistsByKey[$key])) {
                $sourceSetlistsByKey[$key] = new SourceSetlistOutput(
                    bandName: $track->getSourceBand()->getName(),
                    setlistfmId: $sourceSetlistfmId,
                    url: $track->getSourceSong()?->getSetlist()->getUrl(),
                );
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
            resultKind: $resultKind,
            noSetlistCause: $noSetlistCause,
            matchRate: $matchRate,
            createdAt: $playlist->getCreatedAt(),
            report: $report,
            tracks: $tracks,
            sourceSetlists: array_values($sourceSetlistsByKey),
        );
    }
}
