<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Exception\GenerationBlockedException;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\FailureReason;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ResultKind;

/**
 * AC-6 / T-INT-08…T-INT-10 (spec 14 §8, spec 13 §5): the two idempotency mechanisms actually stop a
 * retry from duplicating anything over the real pipeline — the creation marker (level 2) and the
 * insertion watermark (level 3). `TestDoubleStreamingProvider::getCreatePlaylistCallCount()` /
 * `getAddTracksCallCount()` are the load-bearing assertions here: a duplicate would show up as a
 * second call, not just a second row (the row-count alone wouldn't catch "recreated with the same
 * id by coincidence").
 */
final class PlaylistPipelineIdempotentRetryTest extends PlaylistPipelineTestCase
{
    /**
     * A completed job is terminal and is never handed back to `PlaylistPipeline::run()` again — that
     * redelivery guard (T-20) lives in `BuildPlaylistHandler`, one layer up, which re-reads the job
     * inside its lock and returns without work for a terminal/suspended state (spec 14 §3's step 2).
     * The idempotency mechanisms THIS layer owns (the creation marker, the insertion watermark) are
     * proven by resuming a job that genuinely blocked mid-insertion (T-13: `blocked -> queued`) and
     * re-running it — the real shape a retry takes.
     */
    public function testResumingAfterAQuotaBlockDuringInsertionMakesNoSecondCreateCallAndNoDuplicateBatch(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('retry');
        $em->persist($user);
        $band = $this->newBand('Retry Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), ['Song One', 'Song Two', 'Song Three'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'e');
        $this->jobRepository()->save($job);

        // The very first addTracks() call fails — the playlist is already created by then (D-135),
        // but nothing is inserted yet.
        $this->testDoubleProvider()->scriptQuotaExhaustedAtAddTracksCall(1);

        try {
            $this->pipeline()->run($job);
            self::fail('Expected GenerationBlockedException.');
        } catch (GenerationBlockedException $e) {
            self::getContainer()->get(JobStateMachine::class)->block($job, $e->reason, $e->resumableAfter, $e->stage);
        }

        self::assertSame(1, $this->testDoubleProvider()->getCreatePlaylistCallCount());
        self::assertSame(1, $this->testDoubleProvider()->getAddTracksCallCount());

        $playlist = $this->playlistRepository()->findOneBy(['job' => $job]);
        self::assertNotNull($playlist);
        self::assertSame(0, $playlist->getInsertedThroughOrdinal(), 'The failed first batch must not have advanced the watermark.');

        // T-13: the sweeper resumes a blocked job by moving it back to `queued`; the SAME message
        // shape (`BuildPlaylistHandler`) then calls `PlaylistPipeline::run()` on the SAME row again —
        // this is what a real retry/resume looks like at this layer.
        self::getContainer()->get(JobStateMachine::class)->resume($job);
        $this->pipeline()->run($job);

        self::assertSame(1, $this->testDoubleProvider()->getCreatePlaylistCallCount(), 'A resumed run must never call createPlaylist() a second time (D-136) — the marker is already set and confirmed.');
        self::assertSame(2, $this->testDoubleProvider()->getAddTracksCallCount(), 'One failed attempt, one real (successful) attempt — never a duplicate of an already-confirmed batch.');

        $this->entityManager()->clear();
        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertSame(JobState::Completed, $reloadedJob->getState());

        $reloadedPlaylist = $this->playlistRepository()->findOneBy(['job' => $reloadedJob]);
        self::assertNotNull($reloadedPlaylist);
        self::assertCount(3, $reloadedPlaylist->getTracks(), 'No duplicate PlaylistTrack rows must appear.');
        self::assertSame(3, $reloadedPlaylist->getInsertedThroughOrdinal());
        foreach ($reloadedPlaylist->getTracks() as $track) {
            self::assertNotNull($track->getInsertedAt());
        }
    }

    public function testCreationMarkerSetBeforeCreatePlaylistIsPersistedAndSurvivesTheCall(): void
    {
        $job = $this->runToCompletion(['Song One', 'Song Two']);

        $playlist = $this->playlistRepository()->findOneBy(['job' => $job]);
        self::assertNotNull($playlist);

        // D-136: creationAttemptedAt is committed BEFORE createPlaylist() is called, and survives
        // as a fact even after the call succeeds and providerPlaylistId is confirmed.
        self::assertNotNull($playlist->getCreationAttemptedAt());
        self::assertNotNull($playlist->getProviderPlaylistId());
        self::assertFalse($playlist->isCreationIndeterminate());
    }

    /**
     * Simulates a crash between the creation marker being committed and the provider id being
     * confirmed (the exact window D-136 names as the one gap idempotency cannot fully close): the
     * marker is set directly on the entity (as `CreationStage::run()` would have left it mid-call —
     * "committed BEFORE any network call"), `providerPlaylistId` is left null. F-14 must stop rather
     * than risk a second create — never a second `createPlaylist()` call and never `blocked`, always
     * `failed`/`creation_indeterminate` (P-3).
     */
    public function testF14IndeterminateCreationNeverCallsCreatePlaylistASecondTime(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('f14');
        $em->persist($user);
        $band = $this->newBand('F14 Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), ['Song One', 'Song Two'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'd');
        $this->jobRepository()->save($job);

        $playlist = $this->buildMatchedPlaylistWithoutCreating($job);
        $playlist->markCreationAttempted($now);
        $this->entityManager()->flush();

        // The job is currently `building` (this test drove the stages by hand, stopping short of
        // CreationStage) — route it back through `blocked -> queued` (T-13's shape) before handing
        // it to the real pipeline again, exactly as a genuine resume would.
        self::getContainer()->get(JobStateMachine::class)->block($job, \App\Service\Playlist\Model\BlockedReason::UpstreamUnavailable, null, \App\Service\Playlist\Model\PipelineStage::Creation);
        self::getContainer()->get(JobStateMachine::class)->resume($job);

        $this->pipeline()->run($job);

        self::assertSame(0, $this->testDoubleProvider()->getCreatePlaylistCallCount(), 'F-14: the indeterminate case must never risk a second createPlaylist() call.');

        $this->entityManager()->clear();
        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertSame(JobState::Failed, $reloadedJob->getState());
        self::assertSame(FailureReason::CreationIndeterminate, $reloadedJob->getFailureReason());
    }

    /**
     * Runs a full job to `completed` and returns it, asserting the ordinary happy path along the way.
     *
     * @param list<string> $titles
     */
    private function runToCompletion(array $titles): PlaylistGenerationJob
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('retry');
        $em->persist($user);
        $band = $this->newBand('Retry Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), $titles, $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'e');
        $this->jobRepository()->save($job);

        $this->pipeline()->run($job);

        $this->entityManager()->clear();
        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertSame(JobState::Completed, $reloadedJob->getState());
        self::assertSame(ResultKind::Complete, $reloadedJob->getResultKind());

        return $reloadedJob;
    }

    /**
     * Drives the real stage services directly, stopping deliberately right before `CreationStage` —
     * so the returned `Playlist` has real, matched `PlaylistTrack` rows (as a genuine run would have
     * produced by the time it reaches `building`) without this test racing `CreationStage`'s own
     * network call to catch it mid-way.
     */
    private function buildMatchedPlaylistWithoutCreating(PlaylistGenerationJob $job): \App\Entity\Playlist
    {
        self::getContainer()->get(\App\Service\Playlist\Stage\PreflightStage::class)->run($job);
        $playlist = self::getContainer()->get(\App\Service\Playlist\Stage\SetlistSelectionStage::class)->run($job);
        self::getContainer()->get(JobStateMachine::class)->enterMatching($job);
        $provider = self::getContainer()->get(\App\Service\Streaming\StreamingProviderLocator::class)->get($job->getProviderKey());
        $tokens = self::getContainer()->get(\App\Service\Streaming\Link\StreamingTokenManager::class)->usableTokens($job->getStreamingAccount());
        self::getContainer()->get(\App\Service\Playlist\Stage\MatchingStage::class)->run($job, $playlist, $provider, $tokens);
        self::getContainer()->get(JobStateMachine::class)->enterBuilding($job);

        return $playlist;
    }
}
