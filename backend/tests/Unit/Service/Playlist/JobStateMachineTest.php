<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Playlist;

use App\Entity\Concert;
use App\Entity\PlaylistGenerationJob;
use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\FailureReason;
use App\Service\Playlist\Model\JobMode;
use App\Service\Playlist\Model\JobState;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * T-UNIT-12: every legal edge of spec 13 §1's transition table succeeds; the three named illegal
 * ones raise `\LogicException`.
 */
final class JobStateMachineTest extends TestCase
{
    private JobStateMachine $machine;

    protected function setUp(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $this->machine = new JobStateMachine($entityManager, new MockClock());
    }

    public function testQueuedToResolvingSetlist(): void
    {
        $job = $this->makeJob();
        $this->machine->startResolvingSetlist($job);
        self::assertSame(JobState::ResolvingSetlist, $job->getState());
    }

    public function testResolvingSetlistToMatching(): void
    {
        $job = $this->inState(JobState::ResolvingSetlist);
        $this->machine->enterMatching($job);
        self::assertSame(JobState::Matching, $job->getState());
    }

    public function testMatchingToBuilding(): void
    {
        $job = $this->inState(JobState::Matching);
        $this->machine->enterBuilding($job);
        self::assertSame(JobState::Building, $job->getState());
    }

    public function testResolvingSetlistToCompleted(): void
    {
        $job = $this->inState(JobState::ResolvingSetlist);
        $this->machine->complete($job);
        self::assertSame(JobState::Completed, $job->getState());
    }

    public function testMatchingToCompleted(): void
    {
        $job = $this->inState(JobState::Matching);
        $this->machine->complete($job);
        self::assertSame(JobState::Completed, $job->getState());
    }

    public function testBuildingToCompleted(): void
    {
        $job = $this->inState(JobState::Building);
        $this->machine->complete($job);
        self::assertSame(JobState::Completed, $job->getState());
    }

    public function testBlockFromResolvingSetlistMatchingAndBuilding(): void
    {
        foreach ([JobState::ResolvingSetlist, JobState::Matching, JobState::Building] as $from) {
            $job = $this->inState($from);
            $this->machine->block($job, BlockedReason::ProviderQuota, null, null);
            self::assertSame(JobState::Blocked, $job->getState());
            self::assertSame(1, $job->getBlockCycleCount());
        }
    }

    public function testBlockedToQueuedOnResume(): void
    {
        $job = $this->inState(JobState::Blocked);
        $this->machine->resume($job);
        self::assertSame(JobState::Queued, $job->getState());
    }

    public function testBlockedToFailed(): void
    {
        $job = $this->inState(JobState::Blocked);
        $this->machine->fail($job, FailureReason::BlockCyclesExhausted, null);
        self::assertSame(JobState::Failed, $job->getState());
    }

    public function testResolvingSetlistMatchingBuildingToFailed(): void
    {
        foreach ([JobState::ResolvingSetlist, JobState::Matching, JobState::Building] as $from) {
            $job = $this->inState($from);
            $this->machine->fail($job, FailureReason::UnknownProvider, null);
            self::assertSame(JobState::Failed, $job->getState());
        }
    }

    public function testFailedToQueuedOnRetryIncrementsAttempt(): void
    {
        $job = $this->inState(JobState::Failed);
        self::assertSame(1, $job->getAttempt());
        $this->machine->retry($job);
        self::assertSame(JobState::Queued, $job->getState());
        self::assertSame(2, $job->getAttempt());
    }

    public function testAnyNonTerminalToCancelled(): void
    {
        foreach ([JobState::Queued, JobState::ResolvingSetlist, JobState::Matching, JobState::Building, JobState::Blocked, JobState::Failed] as $from) {
            $job = $this->inState($from);
            $this->machine->cancel($job);
            self::assertSame(JobState::Cancelled, $job->getState());
        }
    }

    public function testAwaitingSetlistChoiceAndAwaitingVersionChoiceToExpired(): void
    {
        foreach ([JobState::AwaitingSetlistChoice, JobState::AwaitingVersionChoice] as $from) {
            $job = $this->inState($from);
            $this->machine->expire($job);
            self::assertSame(JobState::Expired, $job->getState());
        }
    }

    /** AC-4.1: expiry keeps `userChoices` (AC-4.3's pre-fill material) and drops the two suspension payloads, both stale the instant the session has lapsed. */
    public function testExpireKeepsUserChoicesButDropsTheSuspensionPayloads(): void
    {
        $job = $this->inState(JobState::AwaitingVersionChoice);
        $job->setCandidateSetlists([]);
        $job->setPendingChoices(['songsTotal' => 0, 'autoResolvedCount' => 0, 'choicesRequiredCount' => 0, 'autoResolved' => [], 'decisions' => []]);
        $job->setUserChoices(['setlistChoices' => [['bandId' => 1, 'setlistfmId' => 'sl-1']]]);

        $this->machine->expire($job);

        self::assertSame(JobState::Expired, $job->getState());
        self::assertNull($job->getCandidateSetlists());
        self::assertNull($job->getPendingChoices());
        self::assertSame(['setlistChoices' => [['bandId' => 1, 'setlistfmId' => 'sl-1']]], $job->getUserChoices());
    }

    public function testAwaitingStatesCanAlsoBeCancelledOrBlocked(): void
    {
        $job = $this->inState(JobState::AwaitingSetlistChoice);
        $this->machine->cancel($job);
        self::assertSame(JobState::Cancelled, $job->getState());

        $job = $this->inState(JobState::AwaitingVersionChoice);
        $this->machine->block($job, BlockedReason::NeedsReauth, null, null);
        self::assertSame(JobState::Blocked, $job->getState());
    }

    /** Illegal by construction (spec 13 §1): a finished playlist is a fact, never regenerated in place. */
    public function testCompletedIsTerminalAndRejectsAnyTransition(): void
    {
        $job = $this->inState(JobState::Completed);
        $this->expectException(\LogicException::class);
        $this->machine->cancel($job);
    }

    /** Illegal by construction: matching results are frozen once `building` is entered. */
    public function testBuildingCannotReturnToMatching(): void
    {
        $job = $this->inState(JobState::Building);
        $this->expectException(\LogicException::class);
        $this->machine->enterMatching($job);
    }

    /** Illegal by construction: an expired job pre-fills a new one, it does not resurrect. */
    public function testExpiredCannotReturnToQueued(): void
    {
        $job = $this->inState(JobState::Expired);
        $this->expectException(\LogicException::class);
        $this->machine->resume($job);
    }

    private function makeJob(): PlaylistGenerationJob
    {
        $now = new \DateTimeImmutable('2026-08-23T12:00:00+00:00');
        $user = new User('listener@example.com', 'hash');
        $concert = new Concert($user, $now, 'Europe/Madrid', $now, $now);
        $account = new StreamingAccount($user, 'test-double', 'token', null, null, [], 'acct-1', null, $now);

        return new PlaylistGenerationJob($user, $concert, 'test-double', $account, JobMode::Fast, str_repeat('a', 64), 1, $now);
    }

    private function inState(JobState $state): PlaylistGenerationJob
    {
        $job = $this->makeJob();

        $reflection = new \ReflectionProperty(PlaylistGenerationJob::class, 'state');
        $reflection->setValue($job, $state);

        return $job;
    }
}
