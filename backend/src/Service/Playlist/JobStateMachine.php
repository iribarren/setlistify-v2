<?php

declare(strict_types=1);

namespace App\Service\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\FailureReason;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\PipelineStage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The ONLY class permitted to assign `PlaylistGenerationJob::$state` (D-159, spec 13 §1, spec 14
 * §5). An illegal edge raises `\LogicException` — a bug, never a user-facing error.
 * `JobStateMachineIsOnlyStateWriterTest` scans `backend/src/` for any other call to
 * `setStateInternal()`.
 *
 * Every transition here commits its own transaction (spec 13 §1's "what is persisted, and when"
 * table) — a worker killed at any instant leaves a row whose state is true.
 */
final readonly class JobStateMachine
{
    /**
     * Every legal edge (spec 13 §1's transition table T-01…T-20), keyed by `from => [to, ...]`.
     * `null` in the `from` position stands for "no prior state" (T-01, the constructor already sets
     * `Queued`, so it is not exercised here) and is therefore omitted.
     *
     * @var array<string, list<string>>
     */
    private const array LEGAL_EDGES = [
        'queued' => ['resolving_setlist', 'queued', 'cancelled'],
        'resolving_setlist' => ['matching', 'awaiting_setlist_choice', 'completed', 'blocked', 'failed', 'cancelled'],
        'awaiting_setlist_choice' => ['matching', 'expired', 'blocked', 'cancelled'],
        'matching' => ['building', 'awaiting_version_choice', 'completed', 'blocked', 'failed', 'cancelled'],
        'awaiting_version_choice' => ['building', 'expired', 'blocked', 'cancelled'],
        'building' => ['completed', 'blocked', 'failed', 'cancelled'],
        'blocked' => ['queued', 'failed', 'cancelled'],
        'failed' => ['queued', 'cancelled'],
        // Terminal — no outgoing edge. A finished playlist is a fact; a regeneration is a new job.
        'completed' => [],
        'expired' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private function transition(PlaylistGenerationJob $job, JobState $to): void
    {
        $from = $job->getState();

        if (!\in_array($to->value, self::LEGAL_EDGES[$from->value], true)) {
            throw new \LogicException(\sprintf('Illegal PlaylistGenerationJob state transition: %s -> %s (job #%s).', $from->value, $to->value, $job->getId() ?? '?'));
        }

        $job->setStateInternal($to, $this->now());
    }

    /** T-02: `queued -> resolving_setlist`. The worker acquired the lock and re-checked availability. */
    public function startResolvingSetlist(PlaylistGenerationJob $job): void
    {
        $now = $this->now();
        $this->transition($job, JobState::ResolvingSetlist);
        $job->markStarted($now);
        $job->enterStage(PipelineStage::Preflight, $now);
        $this->flush();
    }

    /** T-03 / T-05 / T-08: any transition into `matching`. */
    public function enterMatching(PlaylistGenerationJob $job): void
    {
        $this->transition($job, JobState::Matching);
        $job->enterStage(PipelineStage::Matching, $this->now());
        $this->flush();
    }

    /** T-04: `resolving_setlist -> awaiting_setlist_choice` (Normal mode, prompt 17). */
    public function suspendForSetlistChoice(PlaylistGenerationJob $job, \DateTimeImmutable $expiresAt): void
    {
        $this->transition($job, JobState::AwaitingSetlistChoice);
        $job->suspend($this->now(), $expiresAt);
        $this->flush();
    }

    /** T-07: `matching -> awaiting_version_choice` (Normal mode, prompt 17). */
    public function suspendForVersionChoice(PlaylistGenerationJob $job, \DateTimeImmutable $expiresAt): void
    {
        $this->transition($job, JobState::AwaitingVersionChoice);
        $job->suspend($this->now(), $expiresAt);
        $this->flush();
    }

    /** T-06: `matching -> building`. */
    public function enterBuilding(PlaylistGenerationJob $job): void
    {
        $this->transition($job, JobState::Building);
        $job->enterStage(PipelineStage::Creation, $this->now());
        $this->flush();
    }

    /** T-09/T-10/T-11: any transition into `completed`. */
    public function complete(PlaylistGenerationJob $job): void
    {
        $now = $this->now();
        $this->transition($job, JobState::Completed);
        $job->markFinished($now);
        $this->flush();
    }

    /** T-12/T-19: any transition into `blocked`. Nothing computed so far is discarded. */
    public function block(PlaylistGenerationJob $job, BlockedReason $reason, ?\DateTimeImmutable $resumableAfter, ?PipelineStage $stage): void
    {
        $now = $this->now();
        $this->transition($job, JobState::Blocked);
        $job->block($reason, $resumableAfter, $stage, $now);
        $job->incrementBlockCycleCount($now);
        $this->flush();
    }

    /** T-13: `blocked -> queued`, the sweeper's resume. */
    public function resume(PlaylistGenerationJob $job): void
    {
        $this->transition($job, JobState::Queued);
        $this->flush();
    }

    /**
     * T-14/T-15: any transition into `failed`.
     *
     * @param array<string, mixed>|null $detail
     */
    public function fail(PlaylistGenerationJob $job, FailureReason $reason, ?array $detail): void
    {
        $now = $this->now();
        $this->transition($job, JobState::Failed);
        $job->fail($reason, $detail, $now);
        $job->markFinished($now);
        $this->flush();
    }

    /** T-16: `failed -> queued`, the user's retry. Same row, same idempotency key, `attempt++`. */
    public function retry(PlaylistGenerationJob $job): void
    {
        $this->transition($job, JobState::Queued);
        $job->incrementAttempt($this->now());
        $this->flush();
    }

    /** T-17: `awaiting_* -> expired`, found past TTL by `app:playlist:expire-jobs`. */
    public function expire(PlaylistGenerationJob $job): void
    {
        $this->transition($job, JobState::Expired);
        $this->flush();
    }

    /** T-18: any non-terminal state `-> cancelled`. */
    public function cancel(PlaylistGenerationJob $job): void
    {
        $now = $this->now();
        $this->transition($job, JobState::Cancelled);
        $job->markFinished($now);
        $this->flush();
    }

    private function flush(): void
    {
        $this->entityManager->flush();
    }
}
