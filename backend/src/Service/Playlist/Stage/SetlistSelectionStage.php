<?php

declare(strict_types=1);

namespace App\Service\Playlist\Stage;

use App\Entity\Band;
use App\Entity\ConcertBand;
use App\Entity\Playlist;
use App\Entity\PlaylistGenerationJob;
use App\Entity\PlaylistTrack;
use App\Entity\Setlist;
use App\Repository\PlaylistRepository;
use App\Repository\SetlistRepository;
use App\Service\Matching\MedleySplitter;
use App\Service\Playlist\Exception\SetlistBudgetExhaustedException;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Naming\PlaylistNamer;
use App\Service\Playlist\SubstantialSetlistSelector;
use App\Service\Setlist\BandIdentityResolver;
use App\Service\Setlist\SetlistGateway;
use App\Service\Setlist\SetlistNormalizer;
use Psr\Clock\ClockInterface;

/**
 * Per band, in stage order (P-2): resolve setlist.fm identity, read cached `Setlist` rows, apply
 * "most recent substantial" (D-132), spend at most one index page per band if nothing is cached
 * (D-131). Applies the multi-band caps (P-1, D-133) and creates the up-front `PlaylistTrack`
 * skeleton, one row per source song (segment, for a medley) — D-139/D-140.
 *
 * When no band on the lineup has any usable setlist (F-02/F-03), the returned `Playlist` simply
 * carries zero tracks and a `NO_SETLIST_FOR_BAND` report entry per band — `PlaylistPipeline` reads
 * `songsTotal === 0` and completes as `no_source_material` (T-10) without ever entering `matching`.
 * Throws {@see SetlistBudgetExhaustedException} when setlist.fm's daily budget is exhausted and a
 * band has nothing cached to fall back on (F-01).
 */
final readonly class SetlistSelectionStage
{
    public function __construct(
        private SetlistRepository $setlistRepository,
        private PlaylistRepository $playlistRepository,
        private BandIdentityResolver $identityResolver,
        private SetlistGateway $setlistGateway,
        private SetlistNormalizer $setlistNormalizer,
        private SubstantialSetlistSelector $selector,
        private MedleySplitter $medleySplitter,
        private PlaylistNamer $namer,
        private ClockInterface $clock,
        private int $maxBands,
        private int $maxSongs,
        private int $setlistPages,
    ) {
    }

    public function run(PlaylistGenerationJob $job): Playlist
    {
        // Idempotency (spec 14 §5, spec 13 §5): a resumed run (T-13 blocked -> queued) or a retry
        // (T-16 failed -> queued) re-enters the pipeline from `queued`, which re-runs THIS stage —
        // but the `Playlist` and its up-front `PlaylistTrack` skeleton (D-139/D-140) must be created
        // exactly once per job, not once per attempt. Recreating them on every resume would silently
        // orphan a Playlist that may already carry a confirmed `providerPlaylistId` (D-136) or a
        // partially advanced insertion watermark (D-137), defeating both idempotency mechanisms one
        // stage upstream of where they are enforced. A prior attempt that never got this far (e.g.
        // blocked mid-selection by F-01) has no Playlist row yet, so this is a no-op precisely when
        // it should be.
        $existing = $this->playlistRepository->findOneBy(['job' => $job]);
        if (null !== $existing) {
            return $existing;
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $concert = $job->getConcert();

        /** @var list<ConcertBand> $byBillingAsc */
        $byBillingAsc = $concert->getConcertBands()->toArray(); // already ASC by billingOrder (0 = headliner)

        // P-1: keep the highest-billed (lowest billingOrder) bands.
        $kept = \array_slice($byBillingAsc, 0, $this->maxBands);
        $omittedBands = \array_slice($byBillingAsc, $this->maxBands);

        // Stage order for playback: earliest support act first, headliner last (billingOrder DESC).
        $stageOrder = array_reverse($kept);

        $selections = [];
        $reportEntries = [];
        $selectedSetlistsJson = [];

        foreach ($stageOrder as $concertBand) {
            $band = $concertBand->getBand();
            $candidates = $this->cachedCandidates($band);

            if ([] === $candidates) {
                $candidates = $this->fetchOnePage($band, $now);
            }

            $result = $this->selector->select($candidates, $now);

            if (null === $result) {
                $reportEntries[] = [ReportCode::NoSetlistForBand, ['band' => $band->getName()]];
                continue;
            }

            $selections[] = ['band' => $band, 'setlist' => $result->setlist, 'reason' => $result->reason];
            $selectedSetlistsJson[] = [
                'bandId' => $band->getId() ?? 0,
                'setlistfmId' => $result->setlist->getSetlistfmId(),
                'selectionReason' => $result->reason->value,
                'fingerprint' => $result->setlist->getSetlistfmId(),
                'songCount' => $result->setlist->getSongCount(),
            ];
            $reportEntries[] = [ReportCode::SelectedFrom, [
                'band' => $band->getName(),
                'date' => $result->setlist->getEventDate()->format('Y-m-d'),
                'venue' => $result->setlist->getVenueName(),
                'songCount' => $result->setlist->getSongCount(),
                'selectionReason' => $result->reason->value,
            ]];
        }

        if ([] !== $omittedBands) {
            $reportEntries[] = [ReportCode::BandsOmittedForLength, [
                'bands' => array_map(static fn (ConcertBand $cb): string => $cb->getBand()->getName(), $omittedBands),
            ]];
        }

        // T-10 (no band has any usable setlist) is NOT thrown here: the Playlist row below is
        // still created, with zero tracks, so the per-band NO_SETLIST_FOR_BAND report entries are
        // not lost. `PlaylistPipeline` reads `songsTotal === 0` after this stage and completes as
        // `no_source_material` without ever entering `matching`.

        // P-1: GENERATION_MAX_SONGS, cutting whole bands from the lowest-billed end (the front of
        // stage order) until the total fits; last resort, truncate the sole remaining band.
        [$selections, $songLimitBySetlistId] = $this->applySongCap($selections, $reportEntries);

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

    /** @return list<Setlist> ordered by eventDate DESC */
    private function cachedCandidates(Band $band): array
    {
        /** @var list<Setlist> $result */
        $result = $this->setlistRepository->createBandSetlistsQueryBuilder($band)->getQuery()->getResult();

        return $result;
    }

    /** @return list<Setlist> */
    private function fetchOnePage(Band $band, \DateTimeImmutable $now): array
    {
        if ($this->setlistPages < 1) {
            return [];
        }

        $outcome = $this->identityResolver->ensureResolved($band);

        if (\in_array($outcome->state, [Band::RESOLUTION_AMBIGUOUS, Band::RESOLUTION_NO_PRESENCE], true)) {
            return [];
        }

        if (Band::RESOLUTION_RESOLVED !== $outcome->state) {
            // 'unresolved' with an unavailableReason: setlist.fm itself could not be reached to
            // even resolve identity.
            if ('budget_exhausted' === $outcome->unavailableReason) {
                throw new SetlistBudgetExhaustedException(\sprintf('setlist.fm budget exhausted while resolving %s.', $band->getName()), $outcome->budgetResetAt);
            }

            return [];
        }

        $mbid = $band->getSetlistfmMbid();
        if (null === $mbid) {
            return [];
        }

        $fetch = $this->setlistGateway->fetchArtistSetlistsPage($mbid, 1);

        if (null === $fetch->payload) {
            if ('budget_exhausted' === $fetch->reason) {
                throw new SetlistBudgetExhaustedException(\sprintf('setlist.fm budget exhausted fetching setlists for %s.', $band->getName()), $fetch->budgetResetAt);
            }

            return [];
        }

        $hydrated = $this->setlistNormalizer->hydrateSetlistsPage($band, $fetch->payload, $now);

        foreach ($hydrated['setlists'] as $setlist) {
            $this->setlistRepository->save($setlist);
        }

        return $hydrated['setlists'];
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
