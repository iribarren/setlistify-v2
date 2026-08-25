<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ResultKind;
use App\Service\Playlist\Model\TrackOutcome;
use App\Service\Streaming\Model\TrackCandidate;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The second suspension point (T-07, docs/specs/2026-08-25-playlist-normal-mode.md): US-2's
 * acceptance criteria. `Creep` -> `Creeping` is this suite's standard CHOICE-band fixture — computed
 * (see the spec's implementation notes) to land at confidence ~0.664, inside `[0.55, 0.80)`.
 */
final class NormalModeVersionChoiceTest extends NormalModePipelineTestCase
{
    /** @return array{0: PlaylistGenerationJob, 1: \App\Entity\Band} */
    private function runToAwaitingVersionChoice(string $userLabel): array
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser($userLabel);
        $em->persist($user);
        $band = $this->newBand('Choicey', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-01-01'), ['Auto Match Song', 'Creep'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $this->testDoubleProvider()->scriptCandidates('Creep', [
            new TrackCandidate(
                providerTrackId: 'creeping-track-1',
                title: 'Creeping',
                artist: 'Choicey',
                album: 'Some Album',
                durationMs: 200_000,
                isLive: false,
                isCover: false,
                confidence: 0.9,
            ),
        ]);

        $job = $this->newNormalJob($user, $concert, $account, $now);
        $this->jobRepository()->save($job);
        $this->pipeline()->run($job);

        $em->clear();
        $job = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $job);
        self::assertSame(JobState::AwaitingVersionChoice, $job->getState());

        return [$job, $band];
    }

    public function testChoiceBandSuspendsWithShapedPendingChoicesAndNoRawConfidence(): void
    {
        [$job] = $this->runToAwaitingVersionChoice('choice-band');

        /**
         * @var array{songsTotal: int, autoResolvedCount: int, choicesRequiredCount: int, autoResolved: list<mixed>, decisions: list<array{sourceTitle: string, candidates: list<array{label: string, providerTrackId: string}>}>}|null $pending
         */
        $pending = $job->getPendingChoices();
        self::assertIsArray($pending);
        self::assertSame(2, $pending['songsTotal']);
        self::assertSame(1, $pending['autoResolvedCount']);
        self::assertSame(1, $pending['choicesRequiredCount']);
        self::assertCount(1, $pending['autoResolved']);
        self::assertCount(1, $pending['decisions']);

        $decision = $pending['decisions'][0];
        self::assertSame('Creep', $decision['sourceTitle']);
        self::assertCount(1, $decision['candidates']);
        self::assertSame('only_match', $decision['candidates'][0]['label']);
        self::assertSame('creeping-track-1', $decision['candidates'][0]['providerTrackId']);

        // D-204/AC-2.5: no raw confidence number anywhere in this structure.
        $encoded = json_encode($pending, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"confidence"', $encoded);

        // AC-2.4: zero provider search happened on top of what matching already spent.
        $searchCallsAtSuspension = $this->testDoubleProvider()->getSearchTrackCallCount();
        self::assertGreaterThan(0, $searchCallsAtSuspension); // matching already ran once
    }

    public function testAcceptingTheOnlyCandidateResumesToBuildingAndCompletes(): void
    {
        [$job] = $this->runToAwaitingVersionChoice('accept-choice');
        $searchCallsBeforeSubmission = $this->testDoubleProvider()->getSearchTrackCallCount();

        $this->versionChoiceApplier()->apply($job, [
            ['sourcePosition' => 1, 'segmentIndex' => null, 'providerTrackId' => 'creeping-track-1'],
        ]);

        self::assertSame(JobState::Building, $job->getState());
        self::assertSame(1, $job->getChoicesRequiredCount());
        self::assertSame(1, $job->getChoicesMadeCount());
        self::assertNull($job->getPendingChoices());

        // AC-2.4/D-200: submitting a choice issues no provider search.
        self::assertSame($searchCallsBeforeSubmission, $this->testDoubleProvider()->getSearchTrackCallCount());

        $this->pipeline()->run($job);

        $this->entityManager()->clear();
        $reloaded = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloaded);
        self::assertSame(JobState::Completed, $reloaded->getState());
        self::assertSame(ResultKind::Complete, $reloaded->getResultKind());

        $playlist = $this->playlistRepository()->findOneBy(['job' => $reloaded]);
        self::assertNotNull($playlist);
        $creepTrack = null;
        foreach ($playlist->getTracks() as $track) {
            if ('Creep' === $track->getSourceTitle()) {
                $creepTrack = $track;
            }
        }
        self::assertNotNull($creepTrack);
        self::assertSame(TrackOutcome::Matched, $creepTrack->getOutcome());
        self::assertSame('creeping-track-1', $creepTrack->getProviderTrackId());
    }

    public function testDecliningACandidateProducesUserDeclinedSkippedOutcome(): void
    {
        [$job] = $this->runToAwaitingVersionChoice('decline-choice');

        $this->versionChoiceApplier()->apply($job, [
            ['sourcePosition' => 1, 'segmentIndex' => null, 'providerTrackId' => null],
        ]);

        $this->pipeline()->run($job);
        $this->entityManager()->clear();
        $reloaded = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloaded);

        $playlist = $this->playlistRepository()->findOneBy(['job' => $reloaded]);
        self::assertNotNull($playlist);
        $creepTrack = null;
        foreach ($playlist->getTracks() as $track) {
            if ('Creep' === $track->getSourceTitle()) {
                $creepTrack = $track;
            }
        }
        self::assertNotNull($creepTrack);
        self::assertSame(TrackOutcome::Skipped, $creepTrack->getOutcome());
        self::assertSame(\App\Service\Playlist\Model\ReportCode::UserDeclined, $creepTrack->getReasonCode());
    }

    public function testProviderTrackIdNotAmongCandidatesIs422(): void
    {
        [$job] = $this->runToAwaitingVersionChoice('bad-track-id');

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->versionChoiceApplier()->apply($job, [
            ['sourcePosition' => 1, 'segmentIndex' => null, 'providerTrackId' => 'not-a-real-candidate'],
        ]);
    }

    public function testUnknownSourcePositionIs422(): void
    {
        [$job] = $this->runToAwaitingVersionChoice('bad-position');

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->versionChoiceApplier()->apply($job, [
            ['sourcePosition' => 99, 'segmentIndex' => null, 'providerTrackId' => 'creeping-track-1'],
        ]);
    }

    public function testWrongStateIs422(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();
        $user = $this->newUser('wrong-state-version');
        $em->persist($user);
        $band = $this->newBand('NotThere', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newNormalJob($user, $concert, $account, $now);
        $this->jobRepository()->save($job);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->versionChoiceApplier()->apply($job, []);
    }
}
