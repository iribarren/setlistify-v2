<?php

declare(strict_types=1);

namespace App\Service\Playlist\Choice;

use App\Entity\PlaylistGenerationJob;
use App\Entity\Setlist;
use App\Message\BuildPlaylistMessage;
use App\Repository\SetlistRepository;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\SelectionReason;
use App\Service\Playlist\PlaylistSkeletonBuilder;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `POST /api/playlist-generation-jobs/{id}/setlist-choice` (T-05, docs/specs/
 * 2026-08-25-playlist-normal-mode.md). Validates the submitted choices against the persisted
 * `candidateSetlists` payload (never a fresh setlist.fm call — AC-1.2), builds the same
 * `PlaylistTrack` skeleton `Stage\SetlistSelectionStage` would have built automatically (via the
 * shared `PlaylistSkeletonBuilder`), transitions the job straight into `matching` and dispatches a
 * fresh `BuildPlaylistMessage` so a worker picks up matching from there.
 */
final readonly class SetlistChoiceApplier
{
    public function __construct(
        private SetlistRepository $setlistRepository,
        private PlaylistSkeletonBuilder $skeletonBuilder,
        private JobStateMachine $stateMachine,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array{bandId: int, setlistfmId: string}> $choices
     */
    public function apply(PlaylistGenerationJob $job, array $choices): void
    {
        if (JobState::AwaitingSetlistChoice !== $job->getState()) {
            throw new UnprocessableEntityHttpException('This job is not awaiting a setlist choice.');
        }

        /** @var list<array{bandId: int, bandName: string, billingOrder: int, recommendedSetlistfmId: ?string, recommendedReason: ?string, noSetlistCause: ?string, candidates: list<array{setlistfmId: string, eventDate: string, venueName: ?string, cityName: ?string, countryCode: ?string, tourName: ?string, songCount: int, isSameNight: bool, url: ?string}>}> $bandsJson */
        $bandsJson = $job->getCandidateSetlists() ?? [];
        $bandsById = [];
        foreach ($bandsJson as $bandEntry) {
            $bandsById[(int) $bandEntry['bandId']] = $bandEntry;
        }

        $choiceBySetlistfmId = [];
        foreach ($choices as $choice) {
            $choiceBySetlistfmId[(int) $choice['bandId']] = $choice['setlistfmId'];
        }

        $concertBandsByBandId = [];
        foreach ($job->getConcert()->getConcertBands() as $concertBand) {
            $bandId = $concertBand->getBand()->getId() ?? 0;
            if (isset($bandsById[$bandId])) {
                $concertBandsByBandId[$bandId] = $concertBand;
            }
        }

        $selections = [];
        foreach ($bandsById as $bandId => $bandEntry) {
            $isQualifying = null !== $bandEntry['recommendedSetlistfmId'] && [] !== $bandEntry['candidates'];

            if (!$isQualifying) {
                // A band with no usable setlist is an explanatory row, never a question (AC-1.8) —
                // it needs no choice and `PlaylistSkeletonBuilder` writes its NO_SETLIST_FOR_BAND
                // report entry via the concert-band loop below.
                continue;
            }

            $chosenSetlistfmId = $choiceBySetlistfmId[$bandId] ?? null;
            if (null === $chosenSetlistfmId) {
                throw new UnprocessableEntityHttpException(\sprintf('Band %d has candidate setlists and requires a choice.', $bandId));
            }

            $offered = array_column($bandEntry['candidates'], 'setlistfmId');
            if (!\in_array($chosenSetlistfmId, $offered, true)) {
                throw new UnprocessableEntityHttpException(\sprintf('setlistfmId "%s" is not among band %d\'s candidates.', $chosenSetlistfmId, $bandId));
            }

            $concertBand = $concertBandsByBandId[$bandId] ?? throw new UnprocessableEntityHttpException(\sprintf('Unknown bandId %d.', $bandId));
            $setlist = $this->setlistRepository->findOneBy(['setlistfmId' => $chosenSetlistfmId]);
            if (!$setlist instanceof Setlist) {
                // The chosen setlist was purged from cache between suspension and submission —
                // spec 13 §6's staleness table treats this as a resume-time concern
                // (SELECTED_SETLIST_UNAVAILABLE); at submission time it is simply unprocessable —
                // the client refetches and re-renders from server truth (AC-6.5).
                throw new UnprocessableEntityHttpException(\sprintf('setlistfmId "%s" is no longer available.', $chosenSetlistfmId));
            }

            $selections[] = ['band' => $concertBand->getBand(), 'setlist' => $setlist, 'reason' => SelectionReason::UserChosen];
        }

        foreach (array_keys($choiceBySetlistfmId) as $bandId) {
            if (!isset($bandsById[$bandId])) {
                throw new UnprocessableEntityHttpException(\sprintf('Unknown bandId %d.', $bandId));
            }
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $reportEntries = [];
        foreach ($bandsById as $bandEntry) {
            if (null === $bandEntry['recommendedSetlistfmId'] && [] === $bandEntry['candidates']) {
                $reportEntries[] = [ReportCode::NoSetlistForBand, [
                    'band' => $bandEntry['bandName'],
                    'cause' => $bandEntry['noSetlistCause'] ?? 'no_setlist_for_show',
                ]];
            }
        }

        $this->skeletonBuilder->build($job, $selections, $reportEntries, $now);
        $job->setCandidateSetlists(null);

        // Kept through expiry, for AC-4.3's pre-fill: `SetlistSelectionStage` consults a resumed
        // job's `userChoices['setlistChoices']` to recommend the same setlist again, when it is
        // still among the new job's candidates.
        $setlistChoicesJson = [];
        foreach ($choiceBySetlistfmId as $bandId => $setlistfmId) {
            $setlistChoicesJson[] = ['bandId' => $bandId, 'setlistfmId' => $setlistfmId];
        }
        $userChoices = $job->getUserChoices() ?? [];
        $userChoices['setlistChoices'] = $setlistChoicesJson;
        $job->setUserChoices($userChoices);

        $this->stateMachine->enterMatching($job);
        $this->messageBus->dispatch(new BuildPlaylistMessage($job->getId() ?? 0, $job->getAttempt()));
    }
}
