<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlaylistGenerationJobRepository;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\FailureReason;
use App\Service\Playlist\Model\JobMode;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\PipelineStage;
use App\Service\Playlist\Model\ResultKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * One run of the playlist-generation pipeline (spec 13 §1/§2, spec 14 §1). `JobStateMachine` is the
 * only class permitted to assign `$state` (D-159). User-scoped: a cross-owner lookup must 404, not
 * 403 (D-157) — enforced by `App\Security\PlaylistGenerationJobOwnerExtension`, not by this entity.
 *
 * Identity is the project's standard integer surrogate key, not the UUID spec 13 originally sketched
 * (D-146).
 */
#[ORM\Entity(repositoryClass: PlaylistGenerationJobRepository::class)]
#[ORM\Table(name: 'playlist_generation_jobs')]
#[ORM\Index(name: 'idx_pgj_owner', columns: ['owner_id'])]
#[ORM\Index(name: 'idx_pgj_concert', columns: ['concert_id'])]
#[ORM\Index(name: 'idx_pgj_streaming_account', columns: ['streaming_account_id'])]
#[ORM\Index(name: 'idx_pgj_state_resumable', columns: ['state', 'resumable_after'])]
#[ORM\Index(name: 'idx_pgj_state_expires', columns: ['state', 'expires_at'])]
#[ORM\Index(name: 'idx_pgj_owner_concert', columns: ['owner_id', 'concert_id'])]
#[ORM\Index(name: 'idx_pgj_created_at', columns: ['created_at'])]
class PlaylistGenerationJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: Concert::class)]
    #[ORM\JoinColumn(name: 'concert_id', nullable: false, onDelete: 'CASCADE')]
    private Concert $concert;

    /** `StreamingProviderInterface::key()`, a runtime string — never a hardcoded provider name. */
    #[ORM\Column(name: 'provider_key', type: 'string', length: 32)]
    private string $providerKey;

    #[ORM\ManyToOne(targetEntity: StreamingAccount::class)]
    #[ORM\JoinColumn(name: 'streaming_account_id', nullable: false, onDelete: 'CASCADE')]
    private StreamingAccount $streamingAccount;

    #[ORM\Column(type: 'string', length: 16, enumType: JobMode::class)]
    private JobMode $mode;

    #[ORM\Column(type: 'string', length: 32, enumType: JobState::class)]
    private JobState $state;

    /** sha256 hex, per spec 14 §5. Equality mechanism, not the uniqueness mechanism. */
    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 64)]
    private string $idempotencyKey;

    #[ORM\Column(type: 'smallint')]
    private int $attempt = 1;

    #[ORM\Column(name: 'algorithm_version', type: 'smallint')]
    private int $algorithmVersion;

    /** @var array<int, array<string, mixed>>|null Normal-mode suspension payload; always null in Fast mode. */
    #[ORM\Column(name: 'candidate_setlists', type: 'json', nullable: true)]
    private ?array $candidateSetlists = null;

    /** @var array<int, array{bandId: int, setlistfmId: string, selectionReason: string, fingerprint: string, songCount: int}>|null */
    #[ORM\Column(name: 'selected_setlists', type: 'json', nullable: true)]
    private ?array $selectedSetlists = null;

    /** @var array<string, mixed>|null Normal-mode suspension payload. */
    #[ORM\Column(name: 'pending_choices', type: 'json', nullable: true)]
    private ?array $pendingChoices = null;

    /** @var array<string, mixed>|null Kept through expiry, for pre-filling a new job. */
    #[ORM\Column(name: 'user_choices', type: 'json', nullable: true)]
    private ?array $userChoices = null;

    #[ORM\Column(name: 'songs_total', type: 'integer')]
    private int $songsTotal = 0;

    #[ORM\Column(name: 'songs_processed', type: 'integer')]
    private int $songsProcessed = 0;

    #[ORM\Column(name: 'current_stage', type: 'string', length: 24, enumType: PipelineStage::class, nullable: true)]
    private ?PipelineStage $currentStage = null;

    #[ORM\Column(name: 'stage_entered_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $stageEnteredAt = null;

    #[ORM\Column(name: 'blocked_reason', type: 'string', length: 32, enumType: BlockedReason::class, nullable: true)]
    private ?BlockedReason $blockedReason = null;

    #[ORM\Column(name: 'resumable_after', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $resumableAfter = null;

    #[ORM\Column(name: 'blocked_at_stage', type: 'string', length: 24, enumType: PipelineStage::class, nullable: true)]
    private ?PipelineStage $blockedAtStage = null;

    #[ORM\Column(name: 'block_cycle_count', type: 'smallint')]
    private int $blockCycleCount = 0;

    #[ORM\Column(name: 'blocked_ms', type: 'integer')]
    private int $blockedMs = 0;

    #[ORM\Column(name: 'failure_reason', type: 'string', length: 32, enumType: FailureReason::class, nullable: true)]
    private ?FailureReason $failureReason = null;

    /** @var array<string, mixed>|null A code and parameters, never a stack trace. */
    #[ORM\Column(name: 'failure_detail', type: 'json', nullable: true)]
    private ?array $failureDetail = null;

    #[ORM\Column(name: 'result_kind', type: 'string', length: 24, enumType: ResultKind::class, nullable: true)]
    private ?ResultKind $resultKind = null;

    #[ORM\Column(name: 'matched_count', type: 'integer')]
    private int $matchedCount = 0;

    #[ORM\Column(name: 'low_confidence_count', type: 'integer')]
    private int $lowConfidenceCount = 0;

    #[ORM\Column(name: 'not_found_count', type: 'integer')]
    private int $notFoundCount = 0;

    #[ORM\Column(name: 'skipped_count', type: 'integer')]
    private int $skippedCount = 0;

    #[ORM\Column(name: 'region_restricted_count', type: 'integer')]
    private int $regionRestrictedCount = 0;

    #[ORM\Column(name: 'mean_confidence', type: 'float', nullable: true)]
    private ?float $meanConfidence = null;

    #[ORM\Column(name: 'duration_ms', type: 'integer', nullable: true)]
    private ?int $durationMs = null;

    /** @var array<string, int>|null `{preflight, selection, normalize, matching, create, insert, report}` in ms. */
    #[ORM\Column(name: 'stage_timings', type: 'json', nullable: true)]
    private ?array $stageTimings = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'started_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'suspended_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /** Exists solely to make the polling `ETag` cheap (D-150, an addition over spec 13's sketch). */
    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * Normal mode's decision-count instrumentation (D-209, AC-9.1) — written at version suspension
     * and at submission. Null for a job that never reached `awaiting_version_choice` (Fast mode, or
     * a Normal-mode job with an empty CHOICE band, D-195).
     */
    #[ORM\Column(name: 'choices_required_count', type: 'integer', nullable: true)]
    private ?int $choicesRequiredCount = null;

    #[ORM\Column(name: 'choices_made_count', type: 'integer', nullable: true)]
    private ?int $choicesMadeCount = null;

    /** AC-4.3: the expired job this one pre-fills from (D-197). Null for a fresh job. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'resumed_from_job_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $resumedFromJob = null;

    public function __construct(
        User $owner,
        Concert $concert,
        string $providerKey,
        StreamingAccount $streamingAccount,
        JobMode $mode,
        string $idempotencyKey,
        int $algorithmVersion,
        \DateTimeImmutable $now,
    ) {
        $this->owner = $owner;
        $this->concert = $concert;
        $this->providerKey = $providerKey;
        $this->streamingAccount = $streamingAccount;
        $this->mode = $mode;
        $this->state = JobState::Queued;
        $this->idempotencyKey = $idempotencyKey;
        $this->algorithmVersion = $algorithmVersion;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getConcert(): Concert
    {
        return $this->concert;
    }

    public function getProviderKey(): string
    {
        return $this->providerKey;
    }

    public function getStreamingAccount(): StreamingAccount
    {
        return $this->streamingAccount;
    }

    public function getMode(): JobMode
    {
        return $this->mode;
    }

    public function getState(): JobState
    {
        return $this->state;
    }

    /** Only `JobStateMachine` may call this (D-159, enforced by a static test, not by visibility). */
    public function setStateInternal(JobState $state, \DateTimeImmutable $now): void
    {
        $this->state = $state;
        $this->touch($now);
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function incrementAttempt(\DateTimeImmutable $now): void
    {
        ++$this->attempt;
        $this->touch($now);
    }

    public function getAlgorithmVersion(): int
    {
        return $this->algorithmVersion;
    }

    /**
     * Spec 13 §6's staleness-on-resume row 2: `Choice\StalenessReconciler` calls this once it has
     * detected a bump and appended the `RESCORED_AFTER_ALGORITHM_UPDATE` report entry, so every
     * subsequent read of this job (cache keys, `UserTrackPreference` lookups, the backoffice) sees
     * the version generation actually used, not the stale one recorded at job creation.
     */
    public function setAlgorithmVersion(int $algorithmVersion): void
    {
        $this->algorithmVersion = $algorithmVersion;
    }

    /** @return array<int, array<string, mixed>>|null */
    public function getCandidateSetlists(): ?array
    {
        return $this->candidateSetlists;
    }

    /** @param array<int, array<string, mixed>>|null $candidateSetlists */
    public function setCandidateSetlists(?array $candidateSetlists): void
    {
        $this->candidateSetlists = $candidateSetlists;
    }

    /** @return array<int, array{bandId: int, setlistfmId: string, selectionReason: string, fingerprint: string, songCount: int}>|null */
    public function getSelectedSetlists(): ?array
    {
        return $this->selectedSetlists;
    }

    /** @param array<int, array{bandId: int, setlistfmId: string, selectionReason: string, fingerprint: string, songCount: int}> $selectedSetlists */
    public function setSelectedSetlists(array $selectedSetlists): void
    {
        $this->selectedSetlists = $selectedSetlists;
    }

    /** @return array<string, mixed>|null */
    public function getPendingChoices(): ?array
    {
        return $this->pendingChoices;
    }

    /** @param array<string, mixed>|null $pendingChoices */
    public function setPendingChoices(?array $pendingChoices): void
    {
        $this->pendingChoices = $pendingChoices;
    }

    /** @return array<string, mixed>|null */
    public function getUserChoices(): ?array
    {
        return $this->userChoices;
    }

    /** @param array<string, mixed>|null $userChoices */
    public function setUserChoices(?array $userChoices): void
    {
        $this->userChoices = $userChoices;
    }

    public function getSongsTotal(): int
    {
        return $this->songsTotal;
    }

    public function setSongsTotal(int $songsTotal, \DateTimeImmutable $now): void
    {
        $this->songsTotal = $songsTotal;
        $this->touch($now);
    }

    public function getSongsProcessed(): int
    {
        return $this->songsProcessed;
    }

    public function incrementSongsProcessed(\DateTimeImmutable $now): void
    {
        ++$this->songsProcessed;
        $this->touch($now);
    }

    public function getCurrentStage(): ?PipelineStage
    {
        return $this->currentStage;
    }

    public function enterStage(PipelineStage $stage, \DateTimeImmutable $now): void
    {
        $this->currentStage = $stage;
        $this->stageEnteredAt = $now;
        $this->touch($now);
    }

    public function getStageEnteredAt(): ?\DateTimeImmutable
    {
        return $this->stageEnteredAt;
    }

    public function getBlockedReason(): ?BlockedReason
    {
        return $this->blockedReason;
    }

    public function getResumableAfter(): ?\DateTimeImmutable
    {
        return $this->resumableAfter;
    }

    public function getBlockedAtStage(): ?PipelineStage
    {
        return $this->blockedAtStage;
    }

    public function block(BlockedReason $reason, ?\DateTimeImmutable $resumableAfter, ?PipelineStage $stage, \DateTimeImmutable $now): void
    {
        $this->blockedReason = $reason;
        $this->resumableAfter = $resumableAfter;
        $this->blockedAtStage = $stage;
        $this->touch($now);
    }

    public function getBlockCycleCount(): int
    {
        return $this->blockCycleCount;
    }

    public function incrementBlockCycleCount(\DateTimeImmutable $now): void
    {
        ++$this->blockCycleCount;
        $this->touch($now);
    }

    public function getBlockedMs(): int
    {
        return $this->blockedMs;
    }

    public function addBlockedMs(int $ms, \DateTimeImmutable $now): void
    {
        $this->blockedMs += $ms;
        $this->touch($now);
    }

    public function getFailureReason(): ?FailureReason
    {
        return $this->failureReason;
    }

    /** @return array<string, mixed>|null */
    public function getFailureDetail(): ?array
    {
        return $this->failureDetail;
    }

    /** @param array<string, mixed>|null $detail */
    public function fail(FailureReason $reason, ?array $detail, \DateTimeImmutable $now): void
    {
        $this->failureReason = $reason;
        $this->failureDetail = $detail;
        $this->touch($now);
    }

    public function getResultKind(): ?ResultKind
    {
        return $this->resultKind;
    }

    /** @param array<string, int> $stageTimings */
    public function freezeCounters(
        int $matched,
        int $lowConfidence,
        int $notFound,
        int $skipped,
        int $regionRestricted,
        ?float $meanConfidence,
        ?int $durationMs,
        array $stageTimings,
        ResultKind $resultKind,
        \DateTimeImmutable $now,
    ): void {
        $this->matchedCount = $matched;
        $this->lowConfidenceCount = $lowConfidence;
        $this->notFoundCount = $notFound;
        $this->skippedCount = $skipped;
        $this->regionRestrictedCount = $regionRestricted;
        $this->meanConfidence = $meanConfidence;
        $this->durationMs = $durationMs;
        $this->stageTimings = $stageTimings;
        $this->resultKind = $resultKind;
        $this->touch($now);
    }

    public function getMatchedCount(): int
    {
        return $this->matchedCount;
    }

    public function getLowConfidenceCount(): int
    {
        return $this->lowConfidenceCount;
    }

    public function getNotFoundCount(): int
    {
        return $this->notFoundCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function getRegionRestrictedCount(): int
    {
        return $this->regionRestrictedCount;
    }

    public function getMeanConfidence(): ?float
    {
        return $this->meanConfidence;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    /** @return array<string, int>|null */
    public function getStageTimings(): ?array
    {
        return $this->stageTimings;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function markStarted(\DateTimeImmutable $now): void
    {
        $this->startedAt = $now;
        $this->touch($now);
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function markFinished(\DateTimeImmutable $now): void
    {
        $this->finishedAt = $now;
        $this->touch($now);
    }

    public function getSuspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function suspend(\DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void
    {
        $this->suspendedAt = $now;
        $this->expiresAt = $expiresAt;
        $this->touch($now);
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getChoicesRequiredCount(): ?int
    {
        return $this->choicesRequiredCount;
    }

    public function getChoicesMadeCount(): ?int
    {
        return $this->choicesMadeCount;
    }

    /** Written at version suspension (T-07) and refined at submission (T-08) — D-209/AC-9.1. */
    public function setChoiceCounts(int $required, int $made, \DateTimeImmutable $now): void
    {
        $this->choicesRequiredCount = $required;
        $this->choicesMadeCount = $made;
        $this->touch($now);
    }

    public function getResumedFromJob(): ?self
    {
        return $this->resumedFromJob;
    }

    /** AC-4.3: set once, on the fresh job created from an expired one's pre-fill. */
    public function setResumedFromJob(self $job, \DateTimeImmutable $now): void
    {
        $this->resumedFromJob = $job;
        $this->touch($now);
    }

    private function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
