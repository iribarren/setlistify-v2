<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Exception\GenerationBlockedException;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\TrackOutcome;

/**
 * AC-7 / T-INT-11, T-INT-12 (spec 14 §8): quota exhaustion mid-run — both provider-side (F-04,
 * `QuotaExhaustedException` from `searchTrack()`/`addTracks()`) and setlist.fm-side (F-01, the
 * daily budget gate) — lands in `blocked` with the correct `blockedReason`/`resumableAfter`, and
 * whatever was already computed is kept, not rolled back.
 *
 * `PlaylistPipeline::run()` itself only THROWS `GenerationBlockedException` — turning that into the
 * persisted `blocked` state is `BuildPlaylistHandler`'s job (spec 14 §3's step 4), so these tests
 * catch it and call `JobStateMachine::block()` the same way the handler would, exactly as
 * `PlaylistPipelineDegradedOutcomesTest::testProviderDisabledAtPreflightBlocksCleanlyRatherThanFailing()`
 * already establishes the pattern for.
 */
final class PlaylistPipelineQuotaExhaustionTest extends PlaylistPipelineTestCase
{
    private ?string $originalBudgetValue = null;
    private string $budgetKey = '';
    /** @var array<string, ?string> */
    private array $originalBreakerValues = [];
    private const array BREAKER_KEYS = ['setlistfm:breaker:failures', 'setlistfm:breaker:open_until'];

    protected function setUp(): void
    {
        parent::setUp();

        $redis = self::getContainer()->get('setlistfm.redis');
        $this->budgetKey = 'setlistfm:budget:'.(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $value = $redis->get($this->budgetKey);
        $this->originalBudgetValue = \is_string($value) ? $value : null;

        // SetlistFmBudget::acquire() checks the shared circuit breaker BEFORE the daily budget
        // (SetlistFmBudget.php's own read order) — an unrelated test elsewhere in the suite that
        // left the breaker open (a real, if unlikely, source of cross-test flakiness on a shared
        // Redis key, per project memory) would make F-01's "budget_exhausted" scripting below
        // surface as "upstream_unavailable" instead, silently not exercising the code path this
        // test exists for. Snapshot-and-restore, exactly like the budget key above.
        foreach (self::BREAKER_KEYS as $key) {
            $breakerValue = $redis->get($key);
            $this->originalBreakerValues[$key] = \is_string($breakerValue) ? $breakerValue : null;
            $redis->del($key);
        }
    }

    protected function tearDown(): void
    {
        // Never leave the shared setlist.fm daily-budget counter (or the breaker) poisoned for the
        // rest of the suite (project memory: these Redis keys are shared across the whole `test`
        // environment) — restore exactly what was there before this test touched them.
        $redis = self::getContainer()->get('setlistfm.redis');
        if (null === $this->originalBudgetValue) {
            $redis->del($this->budgetKey);
        } else {
            $redis->set($this->budgetKey, $this->originalBudgetValue);
        }

        foreach (self::BREAKER_KEYS as $key) {
            $original = $this->originalBreakerValues[$key] ?? null;
            if (null === $original) {
                $redis->del($key);
            } else {
                $redis->set($key, $original);
            }
        }

        parent::tearDown();
    }

    public function testProviderQuotaExhaustedDuringMatchingBlocksAndKeepsAlreadyMatchedSongs(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('quota-match');
        $em->persist($user);
        $band = $this->newBand('Quota Matching Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);

        $titles = array_map(static fn (int $i): string => \sprintf('Song %d', $i), range(1, 15));
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), $titles, $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'q');
        $this->jobRepository()->save($job);

        // Song 8 of 15 fails the search call with a quota exhaustion — songs 1..7 must already be
        // resolved and committed (JobProgressWriter's per-song transaction) before this fires.
        $this->testDoubleProvider()->scriptQuotaExhaustedAtSearchCall(8);

        $reason = $this->runAndBlock($job);

        self::assertSame(BlockedReason::ProviderQuota, $reason->reason);
        self::assertNull($reason->resumableAfter, 'F-04 mid-matching: no adapter-declared window, so resumableAfter is null (the sweeper is what re-tries it).');

        $this->entityManager()->clear();
        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertSame(JobState::Blocked, $reloadedJob->getState());
        self::assertSame(BlockedReason::ProviderQuota, $reloadedJob->getBlockedReason());

        $playlist = $this->playlistRepository()->findOneBy(['job' => $reloadedJob]);
        self::assertNotNull($playlist);
        $resolvedCount = 0;
        foreach ($playlist->getTracks() as $track) {
            if (TrackOutcome::Pending !== $track->getOutcome()) {
                ++$resolvedCount;
            }
        }
        self::assertGreaterThanOrEqual(7, $resolvedCount, 'Songs matched before the quota hit must be kept, not rolled back.');
        self::assertLessThan(15, $resolvedCount, 'Songs after the quota hit must not have been reached.');
        self::assertNull($playlist->getProviderPlaylistId(), 'D-135: nothing was created yet — matching had not finished.');
    }

    public function testProviderQuotaExhaustedDuringInsertionBlocksAndKeepsTheCreatedPlaylist(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('quota-insert');
        $em->persist($user);
        $band = $this->newBand('Quota Insertion Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), ['Song One', 'Song Two', 'Song Three'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'r');
        $this->jobRepository()->save($job);

        $this->testDoubleProvider()->scriptQuotaExhaustedAtAddTracksCall(1);

        $reason = $this->runAndBlock($job);

        self::assertSame(BlockedReason::ProviderQuota, $reason->reason);

        $this->entityManager()->clear();
        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertSame(JobState::Blocked, $reloadedJob->getState());

        $playlist = $this->playlistRepository()->findOneBy(['job' => $reloadedJob]);
        self::assertNotNull($playlist);
        self::assertNotNull($playlist->getProviderPlaylistId(), 'F-08: the provider playlist already exists and is playable, even though insertion blocked.');
        self::assertSame(0, $playlist->getInsertedThroughOrdinal(), 'The failed batch must not have advanced the watermark.');
    }

    public function testSetlistfmBudgetExhaustedMidRunBlocksWithTheGatewaysResetInstant(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('budget');
        $em->persist($user);
        // Deliberately NOT pre-resolved and NOT cached: BandIdentityResolver::ensureResolved() must
        // reach SetlistGateway::searchArtist() for the exhausted budget to actually bite (F-01).
        $band = $this->newBand('Budget Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newJob($user, $concert, $account, $now, 's');
        $this->jobRepository()->save($job);

        $redis = self::getContainer()->get('setlistfm.redis');
        $redis->set($this->budgetKey, '999999999');

        $reason = $this->runAndBlock($job);

        self::assertSame(BlockedReason::SetlistfmBudget, $reason->reason);
        self::assertNotNull($reason->resumableAfter, 'F-01: resumableAfter must be the gateway-computed UTC midnight reset instant.');

        $this->entityManager()->clear();
        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertSame(JobState::Blocked, $reloadedJob->getState());
        self::assertSame(BlockedReason::SetlistfmBudget, $reloadedJob->getBlockedReason());
        self::assertNotNull($reloadedJob->getResumableAfter());
    }

    /** Runs the job through the real pipeline, expecting a `GenerationBlockedException`, and files it into `blocked` the way `BuildPlaylistHandler` would. */
    private function runAndBlock(PlaylistGenerationJob $job): GenerationBlockedException
    {
        try {
            $this->pipeline()->run($job);
            self::fail('Expected GenerationBlockedException.');
        } catch (GenerationBlockedException $e) {
            self::getContainer()->get(JobStateMachine::class)->block($job, $e->reason, $e->resumableAfter, $e->stage);

            return $e;
        }
    }
}
