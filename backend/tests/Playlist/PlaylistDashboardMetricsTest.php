<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\Band;
use App\Entity\Concert;
use App\Entity\Playlist;
use App\Entity\PlaylistGenerationJob;
use App\Entity\PlaylistTrack;
use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\FailureReason;
use App\Service\Playlist\Model\JobMode;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\PipelineStage;
use App\Service\Playlist\Model\ResultKind;
use App\Service\Playlist\Model\TrackOutcome;
use App\Service\Playlist\PlaylistDashboardMetrics;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The backoffice "Playlist generation (last 7 days)" panel's metrics computation
 * (docs/specs/2026-08-23-spike-playlist-pipeline.md §8, D-141/D-142) — p50/p95 duration, mean
 * match rate, not-found rate, blocked-reason breakdown, top-5 unmatched pairs, and the
 * investigate-threshold flags, all read straight off the frozen metrics columns.
 *
 * `PlaylistGenerationJob::setStateInternal()`/`block()`/`fail()`/`freezeCounters()` are called
 * directly here to build fixtures spanning states a real pipeline run would take much longer to
 * reach — D-159's "only `JobStateMachine` may call `setStateInternal()`" rule is a `src/`-only
 * static scan (`JobStateMachineIsOnlyStateWriterTest`), not enforced against test fixtures.
 */
final class PlaylistDashboardMetricsTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();

        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement('TRUNCATE playlist_tracks, playlists, playlist_generation_jobs, track_resolutions RESTART IDENTITY CASCADE');
        $this->entityManager()->clear();
    }

    public function testSevenDaySummaryComputesAllMetricsFromFrozenColumns(): void
    {
        $em = $this->entityManager();
        $now = new \DateTimeImmutable();
        $unique = uniqid('metrics-', true);

        $user = new User(\sprintf('metrics-%s@example.com', $unique), 'hash');
        $em->persist($user);

        $band = new Band(\sprintf('Metrics Band %s', $unique), \sprintf('metrics band %s', $unique), $now);
        $em->persist($band);

        // `uniq_live_generation` forbids two "live" (queued/blocked/…) jobs sharing a
        // (concert, provider) pair — each live-state fixture job below gets its own concert;
        // terminal-state jobs (completed/failed) are free to share one.
        $concert = new Concert($user, $now, 'Europe/Madrid', $now, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);

        $concertForJobC = new Concert($user, $now, 'Europe/Madrid', $now, $now);
        $concertForJobC->addLineupEntry($band, 0);
        $em->persist($concertForJobC);

        $concertForJobD = new Concert($user, $now, 'Europe/Madrid', $now, $now);
        $concertForJobD->addLineupEntry($band, 0);
        $em->persist($concertForJobD);

        $concertForJobF = new Concert($user, $now, 'Europe/Madrid', $now, $now);
        $concertForJobF->addLineupEntry($band, 0);
        $em->persist($concertForJobF);

        $account = new StreamingAccount($user, TestDoubleStreamingProvider::KEY, 'token', null, null, [], 'acct-'.$unique, null, $now);
        $em->persist($account);
        $em->flush();

        // A: completed, 2 days ago, duration 60s, match rate 9/10 = 0.9.
        $jobA = $this->makeJob($user, $concert, $account, $now->modify('-2 days'));
        $jobA->setSongsTotal(10, $now);
        $jobA->freezeCounters(8, 1, 1, 0, 0, 0.9, 60_000, ['matching' => 100], ResultKind::Partial, $now);
        $jobA->setStateInternal(JobState::Completed, $now);
        $em->persist($jobA);

        // B: completed, 3 days ago, duration 120s, match rate 5/10 = 0.5, 5 not-found songs.
        $jobB = $this->makeJob($user, $concert, $account, $now->modify('-3 days'));
        $jobB->setSongsTotal(10, $now);
        $jobB->freezeCounters(5, 0, 5, 0, 0, 0.6, 120_000, ['matching' => 200], ResultKind::Partial, $now);
        $jobB->setStateInternal(JobState::Completed, $now);
        $em->persist($jobB);

        // C, D: blocked, 1 day ago, same reason — exercises the breakdown grouping.
        $jobC = $this->makeJob($user, $concertForJobC, $account, $now->modify('-1 days'));
        $jobC->block(BlockedReason::ProviderQuota, null, PipelineStage::Matching, $now);
        $jobC->setStateInternal(JobState::Blocked, $now);
        $em->persist($jobC);

        $jobD = $this->makeJob($user, $concertForJobD, $account, $now->modify('-1 days'));
        $jobD->block(BlockedReason::ProviderQuota, null, PipelineStage::Matching, $now);
        $jobD->setStateInternal(JobState::Blocked, $now);
        $em->persist($jobD);

        // E: failed, 1 day ago.
        $jobE = $this->makeJob($user, $concert, $account, $now->modify('-1 days'));
        $jobE->fail(FailureReason::BlockCyclesExhausted, ['cycles' => 5], $now);
        $jobE->setStateInternal(JobState::Failed, $now);
        $em->persist($jobE);

        // F: queued, just now — counts toward "started" only.
        $jobF = $this->makeJob($user, $concertForJobF, $account, $now);
        $em->persist($jobF);

        // G: completed 10 days ago — outside the 7-day window, must be excluded entirely.
        $jobG = $this->makeJob($user, $concert, $account, $now->modify('-10 days'));
        $jobG->setSongsTotal(10, $now);
        $jobG->freezeCounters(10, 0, 0, 0, 0, 1.0, 999_999_999, [], ResultKind::Complete, $now);
        $jobG->setStateInternal(JobState::Completed, $now);
        $em->persist($jobG);

        $em->flush();

        // Playlist tracks for job B's not-found songs — top-unmatched-pairs fixture.
        $playlist = new Playlist($user, $concert, $jobB, TestDoubleStreamingProvider::KEY, 'Metrics playlist', $now);
        $em->persist($playlist);

        $this->addNotFoundTrack($playlist, $band, 0, 'Popular Miss');
        $this->addNotFoundTrack($playlist, $band, 1, 'Popular Miss');
        $this->addNotFoundTrack($playlist, $band, 2, 'Popular Miss');
        $this->addNotFoundTrack($playlist, $band, 3, 'Rare Miss');
        $this->addNotFoundTrack($playlist, $band, 4, 'Another Miss');
        $em->flush();

        $summary = $this->metrics()->sevenDaySummary();

        self::assertSame(6, $summary['jobsStarted'], 'A, B, C, D, E, F are within the window; G is not.');
        self::assertSame(2, $summary['jobsCompleted']);
        self::assertSame(2, $summary['jobsBlocked']);
        self::assertSame(1, $summary['jobsFailed']);

        self::assertSame(60_000.0, $summary['p50DurationMs']);
        self::assertSame(120_000.0, $summary['p95DurationMs']);

        self::assertNotNull($summary['meanMatchRate']);
        self::assertEqualsWithDelta(0.7, $summary['meanMatchRate'], 0.0001, 'mean of 0.9 and 0.5.');

        self::assertNotNull($summary['notFoundRate']);
        self::assertEqualsWithDelta(0.3, $summary['notFoundRate'], 0.0001, '(1 + 5) not-found over (10 + 10) denominator.');

        self::assertSame(['provider_quota' => 2], $summary['blockedReasonBreakdown']);

        self::assertCount(3, $summary['topUnmatchedPairs']);
        self::assertSame('Popular Miss', $summary['topUnmatchedPairs'][0]['title']);
        self::assertSame(3, $summary['topUnmatchedPairs'][0]['count']);

        // 2 blocked / 6 started = 33% > 10%; 0.7 mean match rate < 0.75; p95 120s > 90s.
        self::assertTrue($summary['investigate']['p95Duration']);
        self::assertTrue($summary['investigate']['matchRate']);
        self::assertTrue($summary['investigate']['blockedShare']);
    }

    public function testSevenDaySummaryIsAllZeroesWhenNoJobsExist(): void
    {
        $summary = $this->metrics()->sevenDaySummary();

        self::assertSame(0, $summary['jobsStarted']);
        self::assertSame(0, $summary['jobsCompleted']);
        self::assertSame(0, $summary['jobsBlocked']);
        self::assertSame(0, $summary['jobsFailed']);
        self::assertNull($summary['p50DurationMs']);
        self::assertNull($summary['p95DurationMs']);
        self::assertNull($summary['meanMatchRate']);
        self::assertNull($summary['notFoundRate']);
        self::assertSame([], $summary['blockedReasonBreakdown']);
        self::assertSame([], $summary['topUnmatchedPairs']);
        self::assertFalse($summary['investigate']['p95Duration']);
        self::assertFalse($summary['investigate']['matchRate']);
        self::assertFalse($summary['investigate']['blockedShare']);
        self::assertFalse($summary['investigate']['decisionCount']);
        self::assertNull($summary['normalMode']['medianDecisionCount']);
        self::assertNull($summary['normalMode']['p95DecisionCount']);
        self::assertNull($summary['normalMode']['zeroTapShare']);
        self::assertNull($summary['normalMode']['abandonmentByState']['awaiting_setlist_choice']);
        self::assertNull($summary['normalMode']['abandonmentByState']['awaiting_version_choice']);
    }

    /**
     * D-209/AC-9.2 (docs/specs/2026-08-25-playlist-normal-mode.md): median/p95 decision count, the
     * zero-tap share, and abandonment by suspended state — all read off the same frozen columns as
     * the rest of this panel, no new tracking.
     */
    public function testSevenDaySummaryComputesNormalModeDecisionMetrics(): void
    {
        $em = $this->entityManager();
        $now = new \DateTimeImmutable();
        $unique = uniqid('normal-metrics-', true);

        $user = new User(\sprintf('normal-metrics-%s@example.com', $unique), 'hash');
        $em->persist($user);

        $band = new Band(\sprintf('Normal Metrics Band %s', $unique), \sprintf('normal metrics band %s', $unique), $now);
        $em->persist($band);

        // Terminal-state (completed/expired) fixtures can share one concert; the two still-suspended
        // ("live") fixtures each need their own, per `uniq_live_generation` (one live job per
        // concert+provider).
        $terminalConcert = new Concert($user, $now, 'Europe/Madrid', $now, $now);
        $terminalConcert->addLineupEntry($band, 0);
        $em->persist($terminalConcert);

        $concertForSetlistSuspended = new Concert($user, $now, 'Europe/Madrid', $now, $now);
        $concertForSetlistSuspended->addLineupEntry($band, 0);
        $em->persist($concertForSetlistSuspended);

        $concertForVersionSuspended = new Concert($user, $now, 'Europe/Madrid', $now, $now);
        $concertForVersionSuspended->addLineupEntry($band, 0);
        $em->persist($concertForVersionSuspended);

        $account = new StreamingAccount($user, TestDoubleStreamingProvider::KEY, 'token', null, null, [], 'acct-'.$unique, null, $now);
        $em->persist($account);
        $em->flush();

        // H, I, J: completed, reached the version step — decision counts 3, 7, 5 (median 5, p95 7).
        // H needed zero taps (all defaults accepted); I and J had real decisions.
        $jobH = $this->makeNormalJob($user, $terminalConcert, $account, $now->modify('-1 days'));
        $jobH->setChoiceCounts(3, 0, $now);
        $jobH->setStateInternal(JobState::Completed, $now);
        $em->persist($jobH);

        $jobI = $this->makeNormalJob($user, $terminalConcert, $account, $now->modify('-2 days'));
        $jobI->setChoiceCounts(7, 2, $now);
        $jobI->setStateInternal(JobState::Completed, $now);
        $em->persist($jobI);

        $jobJ = $this->makeNormalJob($user, $terminalConcert, $account, $now->modify('-2 days'));
        $jobJ->setChoiceCounts(5, 5, $now);
        $jobJ->setStateInternal(JobState::Completed, $now);
        $em->persist($jobJ);

        // K: still suspended at setlist choice — never reached the version step.
        $jobK = $this->makeNormalJob($user, $concertForSetlistSuspended, $account, $now->modify('-1 days'));
        $jobK->setStateInternal(JobState::AwaitingSetlistChoice, $now);
        $em->persist($jobK);

        // L: expired before ever reaching the version step (choicesRequiredCount stays null).
        $jobL = $this->makeNormalJob($user, $terminalConcert, $account, $now->modify('-1 days'));
        $jobL->setStateInternal(JobState::Expired, $now);
        $em->persist($jobL);

        // M: still suspended at version choice — already reached it once (choicesRequiredCount set).
        $jobM = $this->makeNormalJob($user, $concertForVersionSuspended, $account, $now->modify('-1 days'));
        $jobM->setChoiceCounts(4, 0, $now);
        $jobM->setStateInternal(JobState::AwaitingVersionChoice, $now);
        $em->persist($jobM);

        // N: expired after having reached the version step at least once.
        $jobN = $this->makeNormalJob($user, $terminalConcert, $account, $now->modify('-1 days'));
        $jobN->setChoiceCounts(6, 1, $now);
        $jobN->setStateInternal(JobState::Expired, $now);
        $em->persist($jobN);

        $em->flush();

        $summary = $this->metrics()->sevenDaySummary();

        self::assertSame(5.0, $summary['normalMode']['medianDecisionCount'], 'median of [3, 5, 7].');
        self::assertSame(7.0, $summary['normalMode']['p95DecisionCount']);
        self::assertEqualsWithDelta(1 / 3, $summary['normalMode']['zeroTapShare'], 0.0001, 'only H needed zero taps, of H/I/J.');
        self::assertEqualsWithDelta(0.5, $summary['normalMode']['abandonmentByState']['awaiting_setlist_choice'], 0.0001, 'L expired / (K still suspended + L expired).');
        self::assertEqualsWithDelta(0.5, $summary['normalMode']['abandonmentByState']['awaiting_version_choice'], 0.0001, 'N expired / (M still suspended + N expired).');

        // Median (5) is not strictly above DECISION_BUDGET (5) — the boundary is exclusive.
        self::assertFalse($summary['investigate']['decisionCount']);
    }

    private function makeJob(User $user, Concert $concert, StreamingAccount $account, \DateTimeImmutable $createdAt): PlaylistGenerationJob
    {
        return new PlaylistGenerationJob(
            $user,
            $concert,
            TestDoubleStreamingProvider::KEY,
            $account,
            JobMode::Fast,
            bin2hex(random_bytes(32)),
            1,
            $createdAt,
        );
    }

    private function makeNormalJob(User $user, Concert $concert, StreamingAccount $account, \DateTimeImmutable $createdAt): PlaylistGenerationJob
    {
        return new PlaylistGenerationJob(
            $user,
            $concert,
            TestDoubleStreamingProvider::KEY,
            $account,
            JobMode::Normal,
            bin2hex(random_bytes(32)),
            1,
            $createdAt,
        );
    }

    private function addNotFoundTrack(Playlist $playlist, Band $band, int $ordinal, string $title): void
    {
        $track = new PlaylistTrack($playlist, $ordinal, null, $band, 'sl-'.$ordinal, $ordinal, $title);
        $track->resolve(TrackOutcome::NotFound, null, null, null, null);
        $playlist->addTrack($track);
        $this->entityManager()->persist($track);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function metrics(): PlaylistDashboardMetrics
    {
        return self::getContainer()->get(PlaylistDashboardMetrics::class);
    }
}
