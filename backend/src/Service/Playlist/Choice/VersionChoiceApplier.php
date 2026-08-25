<?php

declare(strict_types=1);

namespace App\Service\Playlist\Choice;

use App\Entity\PlaylistGenerationJob;
use App\Entity\PlaylistTrack;
use App\Message\BuildPlaylistMessage;
use App\Repository\PlaylistRepository;
use App\Service\Concert\BandResolver;
use App\Service\Matching\MedleySplitter;
use App\Service\Matching\SongNormalizer;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\TrackOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `POST /api/playlist-generation-jobs/{id}/version-choices` (T-08, docs/specs/
 * 2026-08-25-playlist-normal-mode.md). Validates every choice against the persisted `pendingChoices`
 * candidates (never a fresh provider search — AC-2.4/D-200), resolves each CHOICE-band
 * `PlaylistTrack` row directly, records a `UserTrackPreference` for every accepted choice (D-198,
 * never touching `TrackResolution`), transitions the job straight into `building` (T-08) and
 * dispatches a fresh `BuildPlaylistMessage`.
 */
final readonly class VersionChoiceApplier
{
    public function __construct(
        private PlaylistRepository $playlistRepository,
        private PreferenceRecorder $preferenceRecorder,
        private JobStateMachine $stateMachine,
        private MedleySplitter $medleySplitter,
        private SongNormalizer $songNormalizer,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /** @param list<array{sourcePosition: int, segmentIndex: ?int, providerTrackId: ?string}> $choices */
    public function apply(PlaylistGenerationJob $job, array $choices): void
    {
        if (JobState::AwaitingVersionChoice !== $job->getState()) {
            throw new UnprocessableEntityHttpException('This job is not awaiting version choices.');
        }

        $playlist = $this->playlistRepository->findOneBy(['job' => $job]);
        if (null === $playlist) {
            throw new UnprocessableEntityHttpException('This job has no playlist skeleton yet.');
        }

        $pending = $job->getPendingChoices() ?? [];
        /** @var list<array{sourcePosition: int, segmentIndex: ?int, bandName: string, sourceTitle: string, reasonCode: ?string, reasonParams: ?array<string, mixed>, candidates: list<array{providerTrackId: string, title: ?string, artistName: ?string, albumName: ?string, releaseYear: ?int, durationMs: ?int, label: string}>}> $decisions */
        $decisions = $pending['decisions'] ?? [];
        $decisionsByKey = [];
        foreach ($decisions as $decision) {
            $decisionsByKey[self::key($decision['sourcePosition'], $decision['segmentIndex'])] = $decision;
        }

        $tracksByKey = [];
        foreach ($playlist->getTracks() as $track) {
            if (TrackOutcome::MatchedLowConfidence === $track->getOutcome()) {
                $tracksByKey[self::key($track->getSourcePosition(), $track->getSegmentIndex())] = $track;
            }
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $seen = [];

        foreach ($choices as $choice) {
            $key = self::key($choice['sourcePosition'], $choice['segmentIndex'] ?? null);

            $track = $tracksByKey[$key] ?? null;
            if (!$track instanceof PlaylistTrack) {
                throw new UnprocessableEntityHttpException(\sprintf('Unknown sourcePosition %d.', $choice['sourcePosition']));
            }
            $decision = $decisionsByKey[$key] ?? null;
            $seen[$key] = true;

            $providerTrackId = $choice['providerTrackId'];

            if (null === $providerTrackId) {
                // AC-2.6: an explicit decline — a success path, not a miss.
                $track->resolve(TrackOutcome::Skipped, null, null, ReportCode::UserDeclined, []);
                continue;
            }

            $candidateIds = array_column(null !== $decision ? $decision['candidates'] : [], 'providerTrackId');
            if (!\in_array($providerTrackId, $candidateIds, true)) {
                throw new UnprocessableEntityHttpException(\sprintf('providerTrackId "%s" is not among the persisted candidates for sourcePosition %d.', $providerTrackId, $choice['sourcePosition']));
            }

            $track->resolve(TrackOutcome::Matched, $providerTrackId, $track->getConfidence(), $track->getReasonCode(), $track->getReasonParams());

            $this->recordPreference($job, $track, $providerTrackId);
        }

        foreach (array_keys($decisionsByKey) as $key) {
            if (!isset($seen[$key])) {
                throw new UnprocessableEntityHttpException('Every decision requires a choice (accept a candidate, or decline).');
            }
        }

        $this->entityManager->flush();

        $requiredCount = $job->getChoicesRequiredCount() ?? \count($decisionsByKey);
        $job->setChoiceCounts($requiredCount, \count($choices), $now);
        $job->setPendingChoices(null);

        $this->stateMachine->enterBuilding($job);
        $this->messageBus->dispatch(new BuildPlaylistMessage($job->getId() ?? 0, $job->getAttempt()));
    }

    /** AC-5.1: writes the user's preference. Never touches `TrackResolution` (AC-5.5). */
    private function recordPreference(PlaylistGenerationJob $job, PlaylistTrack $track, string $providerTrackId): void
    {
        $song = $track->getSourceSong();
        $expectedArtist = $song?->getCoverOfName() ?? $track->getSourceBand()->getName();
        $normalizedArtist = BandResolver::normalize($expectedArtist);

        $segments = $this->medleySplitter->split($track->getSourceTitle());
        $segmentTitle = $segments[$track->getSegmentIndex() ?? 0] ?? $track->getSourceTitle();
        $normalizedTitle = $this->songNormalizer->normalize($segmentTitle)->comparisonCore;

        $this->preferenceRecorder->record(
            $job->getOwner(),
            $job->getProviderKey(),
            $job->getAlgorithmVersion(),
            $normalizedArtist,
            $normalizedTitle,
            $providerTrackId,
        );
    }

    private static function key(int $sourcePosition, ?int $segmentIndex): string
    {
        return $sourcePosition.':'.($segmentIndex ?? '-');
    }
}
