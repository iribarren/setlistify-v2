<?php

declare(strict_types=1);

namespace App\Service\Playlist;

use App\Entity\Band;
use App\Entity\Playlist;
use App\Entity\PlaylistGenerationJob;
use App\Entity\PlaylistTrack;
use App\Entity\Setlist;
use App\Repository\PlaylistRepository;
use App\Service\Matching\MedleySplitter;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Naming\PlaylistNamer;

/**
 * The up-front `PlaylistTrack` skeleton (D-139/D-140) and `PlaylistGenerationJob::$selectedSetlists`
 * — shared between `Stage\SetlistSelectionStage` (the automatic path, T-03) and
 * `Choice\SetlistChoiceApplier` (the user-chosen path, T-05) so the two never drift on how a
 * `Setlist` becomes an ordered set of `PlaylistTrack` rows, medley splitting included (D-114).
 */
final readonly class PlaylistSkeletonBuilder
{
    public function __construct(
        private PlaylistRepository $playlistRepository,
        private MedleySplitter $medleySplitter,
        private PlaylistNamer $namer,
        private int $maxSongs,
    ) {
    }

    /**
     * @param list<array{band: Band, setlist: Setlist, reason: \App\Service\Playlist\Model\SelectionReason}> $selections
     * @param list<array{0: ReportCode, 1: array<string, mixed>}>                                            $reportEntries additional
     *                                                                                                                       job-level report
     *                                                                                                                       entries (e.g.
     *                                                                                                                       `NO_SETLIST_FOR_BAND`,
     *                                                                                                                       `BANDS_OMITTED_FOR_LENGTH`)
     *                                                                                                                       already collected by
     *                                                                                                                       the caller
     */
    public function build(PlaylistGenerationJob $job, array $selections, array $reportEntries, \DateTimeImmutable $now): Playlist
    {
        // `selectedSetlists` records every band selected BEFORE the song-length cap trims the
        // playlist itself — the pre-existing contract `PlaylistPipelineMultiBandStageOrderTest`
        // pins: the max-bands cap has already run by the time `$selections` reaches this method, but
        // the song-length cap (`applySongCap()`, immediately below) has not.
        $selectedSetlistsJson = array_map(static fn (array $selection): array => [
            'bandId' => $selection['band']->getId() ?? 0,
            'setlistfmId' => $selection['setlist']->getSetlistfmId(),
            'selectionReason' => $selection['reason']->value,
            'fingerprint' => $selection['setlist']->getSetlistfmId(),
            'songCount' => $selection['setlist']->getSongCount(),
        ], $selections);

        [$selections, $songLimitBySetlistId] = $this->applySongCap($selections, $reportEntries);

        $concert = $job->getConcert();
        $playlist = new Playlist($job->getOwner(), $concert, $job, $job->getProviderKey(), $this->namer->name($concert), $now);

        $ordinal = 0;
        $songsTotal = 0;

        foreach ($selections as $selection) {
            /** @var Band $band */
            $band = $selection['band'];
            /** @var Setlist $setlist */
            $setlist = $selection['setlist'];

            $songLimit = $songLimitBySetlistId[$setlist->getId()] ?? null;
            $songIndex = 0;
            foreach ($setlist->getSongs() as $song) {
                if (null !== $songLimit && $songIndex++ >= $songLimit) {
                    break;
                }
                $segments = $this->medleySplitter->split($song->getTitle());
                $segmentCount = \count($segments);

                foreach (array_keys($segments) as $index) {
                    $track = new PlaylistTrack(
                        $playlist,
                        $ordinal++,
                        $song,
                        $band,
                        $setlist->getSetlistfmId(),
                        $song->getPosition(),
                        $song->getTitle(),
                        $segmentCount > 1 ? $index : null,
                    );
                    $playlist->addTrack($track);
                    ++$songsTotal;
                }
            }
        }

        foreach ($reportEntries as [$code, $params]) {
            $playlist->addReportEntry($code->value, $params, $now);
        }

        $job->setSelectedSetlists($selectedSetlistsJson);
        $job->setSongsTotal($songsTotal, $now);

        $this->playlistRepository->save($playlist);

        return $playlist;
    }

    /**
     * @param list<array{band: Band, setlist: Setlist, reason: \App\Service\Playlist\Model\SelectionReason}> $selections
     * @param list<array{0: ReportCode, 1: array<string, mixed>}>                                            $reportEntries
     *
     * @return array{0: list<array{band: Band, setlist: Setlist, reason: \App\Service\Playlist\Model\SelectionReason}>, 1: array<int, int>}
     */
    private function applySongCap(array $selections, array &$reportEntries): array
    {
        $total = array_sum(array_map(static fn (array $s): int => $s['setlist']->getSongCount(), $selections));

        while ($total > $this->maxSongs && \count($selections) > 1) {
            $dropped = array_shift($selections);
            $total -= $dropped['setlist']->getSongCount();
            $reportEntries[] = [ReportCode::BandsOmittedForLength, ['bands' => [$dropped['band']->getName()]]];
        }

        /** @var array<int, int> $songLimitBySetlistId */
        $songLimitBySetlistId = [];
        if ($total > $this->maxSongs && 1 === \count($selections)) {
            // Last resort: the sole remaining band's setlist alone exceeds the cap. Never mutate
            // the shared `Setlist` row (D-66, reference data) — instead cap how many of its songs
            // are read when building this run's PlaylistTrack skeleton.
            $soleId = $selections[0]['setlist']->getId();
            if (null !== $soleId) {
                $songLimitBySetlistId[$soleId] = $this->maxSongs;
            }
            $reportEntries[] = [ReportCode::SetlistTruncated, ['keptSongs' => $this->maxSongs, 'originalSongs' => $total]];
        }

        return [$selections, $songLimitBySetlistId];
    }
}
