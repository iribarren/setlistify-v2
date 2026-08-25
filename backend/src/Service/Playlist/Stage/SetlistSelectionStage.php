<?php

declare(strict_types=1);

namespace App\Service\Playlist\Stage;

use App\Entity\Band;
use App\Entity\ConcertBand;
use App\Entity\Playlist;
use App\Entity\PlaylistGenerationJob;
use App\Entity\Setlist;
use App\Repository\PlaylistRepository;
use App\Repository\SetlistRepository;
use App\Service\Playlist\Exception\SetlistBudgetExhaustedException;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\JobMode;
use App\Service\Playlist\Model\NoSetlistCause;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\PlaylistSkeletonBuilder;
use App\Service\Playlist\SelectionResult;
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
 * **Normal mode's setlist-choice guard lives here (T-04, D-188, docs/specs/
 * 2026-08-25-playlist-normal-mode.md)** — the only other guard is `ReviewStage`'s (T-07); nowhere
 * else in `App\Service\Playlist\` may branch on `$job->getMode()` (AC-7.2). When at least one kept
 * band offers two or more usable candidates, this stage persists `candidateSetlists`, suspends the
 * job via `JobStateMachine::suspendForSetlistChoice()` and returns `null` — `PlaylistPipeline` reads
 * that `null` (a return-value check, not a mode check) and stops. A single usable setlist per band
 * (AC-1.5), or Fast mode, never suspends: T-03's `only_one_available` still applies automatically.
 *
 * When no band on the lineup has any usable setlist (F-02/F-03), the returned `Playlist` simply
 * carries zero tracks and a `NO_SETLIST_FOR_BAND` report entry per band — `PlaylistPipeline` reads
 * `songsTotal === 0` and completes as `no_source_material` (T-10) without ever entering `matching`.
 * Throws {@see SetlistBudgetExhaustedException} when setlist.fm's daily budget is exhausted and a
 * band has nothing cached to fall back on (F-01).
 */
final readonly class SetlistSelectionStage
{
    /** AC-1.3, spec 13 §9: up to this many candidates are offered per band. */
    private const int SELECTION_WINDOW = 20;

    public function __construct(
        private SetlistRepository $setlistRepository,
        private PlaylistRepository $playlistRepository,
        private BandIdentityResolver $identityResolver,
        private SetlistGateway $setlistGateway,
        private SetlistNormalizer $setlistNormalizer,
        private SubstantialSetlistSelector $selector,
        private PlaylistSkeletonBuilder $skeletonBuilder,
        private JobStateMachine $stateMachine,
        private ClockInterface $clock,
        private int $maxBands,
        private int $setlistPages,
        private int $suspendedSetlistChoiceTtlSeconds,
    ) {
    }

    public function run(PlaylistGenerationJob $job): ?Playlist
    {
        // Idempotency (spec 14 §5, spec 13 §5): a resumed run (T-13 blocked -> queued, T-05
        // awaiting_setlist_choice -> matching, T-08 awaiting_version_choice -> building) or a retry
        // (T-16 failed -> queued) re-enters the pipeline from the top — but the `Playlist` and its
        // up-front `PlaylistTrack` skeleton (D-139/D-140) must be created exactly once per job, not
        // once per attempt. A prior attempt that suspended for a setlist choice has no Playlist row
        // yet (this method returned `null` without creating one), so this is a no-op precisely when
        // it should be: `App\Service\Playlist\Choice\SetlistChoiceApplier` builds the skeleton once
        // the user answers, and every subsequent resume short-circuits here.
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

        /** @var list<array{concertBand: ConcertBand, candidates: list<Setlist>, result: ?SelectionResult}> $perBand */
        $perBand = [];
        foreach ($stageOrder as $concertBand) {
            $band = $concertBand->getBand();
            $candidates = $this->cachedCandidates($band);

            if ([] === $candidates) {
                $candidates = $this->fetchOnePage($band, $now);
            }

            $perBand[] = [
                'concertBand' => $concertBand,
                'candidates' => $candidates,
                'result' => $this->selector->select($candidates, $now),
            ];
        }

        if (JobMode::Normal === $job->getMode() && self::anyBandOffersAChoice($perBand)) {
            $this->suspendForSetlistChoice($job, $perBand, $now);

            return null;
        }

        return $this->buildSkeleton($job, $perBand, $omittedBands, $now);
    }

    /** @param list<array{concertBand: ConcertBand, candidates: list<Setlist>, result: ?SelectionResult}> $perBand */
    private static function anyBandOffersAChoice(array $perBand): bool
    {
        foreach ($perBand as $entry) {
            $usable = array_filter($entry['candidates'], static fn (Setlist $s): bool => !$s->isEmpty() && $s->getSongCount() > 0);
            if (\count($usable) >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * T-04: persists `candidateSetlists` (bands ordered `billingOrder` ASC — headliner first, D-25)
     * and suspends. Zero setlist.fm calls happen here — every candidate was already fetched above,
     * from cached rows or the single page-1 fetch `fetchOnePage()` already spent (AC-1.2).
     *
     * @param list<array{concertBand: ConcertBand, candidates: list<Setlist>, result: ?SelectionResult}> $perBand
     */
    private function suspendForSetlistChoice(PlaylistGenerationJob $job, array $perBand, \DateTimeImmutable $now): void
    {
        $concertDate = $job->getConcert()->getDate()->format('Y-m-d');

        $bandsJson = [];
        foreach ($perBand as $entry) {
            $concertBand = $entry['concertBand'];
            $band = $concertBand->getBand();
            $result = $entry['result'];

            $candidatesJson = [];
            foreach (\array_slice($entry['candidates'], 0, self::SELECTION_WINDOW) as $setlist) {
                $candidatesJson[] = [
                    'setlistfmId' => $setlist->getSetlistfmId(),
                    'eventDate' => $setlist->getEventDate()->format('Y-m-d'),
                    'venueName' => $setlist->getVenueName(),
                    'cityName' => $setlist->getVenueCity(),
                    'countryCode' => $setlist->getVenueCountry(),
                    'tourName' => $setlist->getTourName(),
                    'songCount' => $setlist->getSongCount(),
                    'isSameNight' => $setlist->getEventDate()->format('Y-m-d') === $concertDate,
                    'url' => $setlist->getUrl(),
                ];
            }

            $bandsJson[] = [
                'bandId' => $band->getId() ?? 0,
                'bandName' => $band->getName(),
                'billingOrder' => $concertBand->getBillingOrder(),
                'recommendedSetlistfmId' => $result?->setlist->getSetlistfmId(),
                'recommendedReason' => $result?->reason->value,
                'noSetlistCause' => null === $result ? NoSetlistCause::forResolutionState($band->getSetlistfmResolutionState())->value : null,
                'candidates' => $candidatesJson,
            ];
        }

        usort($bandsJson, static fn (array $a, array $b): int => $a['billingOrder'] <=> $b['billingOrder']);

        $job->setCandidateSetlists($bandsJson);
        $expiresAt = $now->modify(\sprintf('+%d seconds', $this->suspendedSetlistChoiceTtlSeconds));
        $this->stateMachine->suspendForSetlistChoice($job, $expiresAt);
    }

    /**
     * The automatic path (Fast mode, or Normal mode where every band has at most one usable
     * setlist, T-03) — unchanged from the pre-Normal-mode behaviour.
     *
     * @param list<array{concertBand: ConcertBand, candidates: list<Setlist>, result: ?SelectionResult}> $perBand
     * @param list<ConcertBand>                                                                          $omittedBands
     */
    private function buildSkeleton(PlaylistGenerationJob $job, array $perBand, array $omittedBands, \DateTimeImmutable $now): Playlist
    {
        $selections = [];
        $reportEntries = [];

        foreach ($perBand as $entry) {
            $band = $entry['concertBand']->getBand();
            $result = $entry['result'];

            if (null === $result) {
                $reportEntries[] = [ReportCode::NoSetlistForBand, [
                    'band' => $band->getName(),
                    'cause' => NoSetlistCause::forResolutionState($band->getSetlistfmResolutionState())->value,
                ]];
                continue;
            }

            $selections[] = ['band' => $band, 'setlist' => $result->setlist, 'reason' => $result->reason];
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

        // T-10 (no band has any usable setlist) is NOT thrown here: `PlaylistSkeletonBuilder` still
        // creates the Playlist row, with zero tracks, so the per-band NO_SETLIST_FOR_BAND report
        // entries are not lost. `PlaylistPipeline` reads `songsTotal === 0` after this stage and
        // completes as `no_source_material` without ever entering `matching`.
        return $this->skeletonBuilder->build($job, $selections, $reportEntries, $now);
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
}
