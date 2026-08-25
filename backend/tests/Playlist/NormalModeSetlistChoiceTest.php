<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ResultKind;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The first suspension point (T-04, docs/specs/2026-08-25-playlist-normal-mode.md): US-1's
 * acceptance criteria, run against the real pipeline and `PlaylistPipelineTestCase`'s
 * `TestDoubleStreamingProvider` fixture shape.
 */
final class NormalModeSetlistChoiceTest extends NormalModePipelineTestCase
{
    public function testExactlyOneUsableSetlistNeverSuspends(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('one-setlist');
        $em->persist($user);
        $band = $this->newBand('OnlyOne', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-01-01'), ['Song One', 'Song Two'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newNormalJob($user, $concert, $account, $now);
        $this->jobRepository()->save($job);

        $this->pipeline()->run($job);

        // AC-1.5: no suspension — straight through to completion (empty CHOICE band, exact matches).
        $em->clear();
        $reloaded = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloaded);
        self::assertSame(JobState::Completed, $reloaded->getState());
        self::assertNull($reloaded->getCandidateSetlists());
    }

    public function testMultipleCandidatesSuspendForSetlistChoiceWithoutAnySetlistfmOrProviderCall(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('multi-setlist');
        $em->persist($user);
        $band = $this->newBand('ManyShows', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-01-01'), ['Song One'], $now);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-06-01'), ['Song Two', 'Song Three'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newNormalJob($user, $concert, $account, $now);
        $this->jobRepository()->save($job);

        $this->pipeline()->run($job);

        $em->clear();
        $reloaded = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloaded);
        self::assertSame(JobState::AwaitingSetlistChoice, $reloaded->getState());
        self::assertNotNull($reloaded->getExpiresAt());

        /** @var list<array{candidates: list<mixed>, recommendedSetlistfmId: ?string}>|null $bands */
        $bands = $reloaded->getCandidateSetlists();
        self::assertIsArray($bands);
        self::assertCount(1, $bands);
        self::assertCount(2, $bands[0]['candidates']);
        self::assertNotNull($bands[0]['recommendedSetlistfmId']);

        // AC-1.2/AC-2.4: zero provider search happened — matching never started.
        self::assertSame(0, $this->testDoubleProvider()->getSearchTrackCallCount());
    }

    public function testSubmittingTheChoiceResumesToMatchingAndCompletes(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('choose-setlist');
        $em->persist($user);
        $band = $this->newBand('PickMe', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-01-01'), ['Old Song'], $now);
        $chosen = $this->newCachedSetlist($band, new \DateTimeImmutable('2023-06-01'), ['New Song One', 'New Song Two'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newNormalJob($user, $concert, $account, $now);
        $this->jobRepository()->save($job);
        $this->pipeline()->run($job);

        $em->clear();
        $job = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $job);
        self::assertSame(JobState::AwaitingSetlistChoice, $job->getState());

        $this->setlistChoiceApplier()->apply($job, [['bandId' => $band->getId() ?? 0, 'setlistfmId' => $chosen->getSetlistfmId()]]);
        self::assertSame(JobState::Matching, $job->getState());

        // T-05: a fresh BuildPlaylistMessage was dispatched — the worker resumes from here in
        // production; the test drives the resumed pipeline run directly.
        $this->pipeline()->run($job);

        $em->clear();
        $reloaded = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloaded);
        self::assertSame(JobState::Completed, $reloaded->getState());
        self::assertSame(ResultKind::Complete, $reloaded->getResultKind());
        self::assertSame(2, $reloaded->getSongsTotal());

        $playlist = $this->playlistRepository()->findOneBy(['job' => $reloaded]);
        self::assertNotNull($playlist);
        self::assertSame(['New Song One', 'New Song Two'], array_map(
            static fn ($t) => $t->getSourceTitle(),
            $playlist->getTracks()->toArray(),
        ));
    }

    public function testSubmittingBeforeExactlyOneQualifyingBandIsAnsweredIs422(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('unanswered');
        $em->persist($user);
        $band = $this->newBand('NeedsAnswer', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-01-01'), ['Song One'], $now);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-06-01'), ['Song Two'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newNormalJob($user, $concert, $account, $now);
        $this->jobRepository()->save($job);
        $this->pipeline()->run($job);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->setlistChoiceApplier()->apply($job, []);
    }

    public function testWrongStateIs422(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('wrong-state');
        $em->persist($user);
        $band = $this->newBand('NotSuspended', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-01-01'), ['Song One'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        // Still `queued` — never run through the pipeline.
        $job = $this->newNormalJob($user, $concert, $account, $now);
        $this->jobRepository()->save($job);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->setlistChoiceApplier()->apply($job, [['bandId' => $band->getId() ?? 0, 'setlistfmId' => 'whatever']]);
    }
}
