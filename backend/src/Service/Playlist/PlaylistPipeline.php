<?php

declare(strict_types=1);

namespace App\Service\Playlist;

use App\Entity\Playlist;
use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Exception\GenerationBlockedException;
use App\Service\Playlist\Exception\PlaylistCreationIndeterminateException;
use App\Service\Playlist\Exception\SetlistBudgetExhaustedException;
use App\Service\Playlist\Exception\UnknownProviderInPipelineException;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\FailureReason;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\PipelineStage;
use App\Service\Playlist\Model\ResultKind;
use App\Service\Playlist\Naming\PlaylistNamer;
use App\Service\Playlist\Stage\CreationStage;
use App\Service\Playlist\Stage\InsertionStage;
use App\Service\Playlist\Stage\MatchingStage;
use App\Service\Playlist\Stage\PreflightStage;
use App\Service\Playlist\Stage\ReportStage;
use App\Service\Playlist\Stage\ReviewStage;
use App\Service\Playlist\Stage\SetlistSelectionStage;
use App\Service\Provider\ProviderAvailability;
use App\Service\Provider\ProviderDisabledException;
use App\Service\Streaming\Exception\TokenExpiredException;
use App\Service\Streaming\Link\StreamingTokenManager;
use App\Service\Streaming\Model\ProviderTokens;
use App\Service\Streaming\StreamingProviderLocator;
use Psr\Clock\ClockInterface;

/**
 * The ordered stages, and the ONE entry point both Fast mode (this feature) and Normal mode
 * (prompt 17) use (AC-6.2). Match everything first, create the playlist last (D-135) — see the
 * `CreationStage`/`InsertionStage` ordering below, which is deliberate and not negotiable (spec 14
 * §3). `ProviderRegistry::isAvailable()` is re-checked at every stage boundary (D-134/F-07).
 */
final readonly class PlaylistPipeline
{
    public function __construct(
        private PreflightStage $preflightStage,
        private SetlistSelectionStage $setlistSelectionStage,
        private MatchingStage $matchingStage,
        private ReviewStage $reviewStage,
        private CreationStage $creationStage,
        private InsertionStage $insertionStage,
        private ReportStage $reportStage,
        private JobStateMachine $stateMachine,
        private StreamingProviderLocator $locator,
        private ProviderAvailability $providerAvailability,
        private StreamingTokenManager $tokenManager,
        private PlaylistNamer $namer,
        private ClockInterface $clock,
    ) {
    }

    public function run(PlaylistGenerationJob $job): void
    {
        $stageTimings = [];
        $stageStart = microtime(true);

        try {
            $this->preflightStage->run($job);
        } catch (UnknownProviderInPipelineException $e) {
            $this->stateMachine->fail($job, FailureReason::UnknownProvider, ['message' => $e->getMessage()]);

            return;
        }
        $stageTimings['preflight'] = self::elapsedMs($stageStart);

        $this->assertProviderAvailable($job, PipelineStage::SetlistSelection);

        $stageStart = microtime(true);
        try {
            $playlist = $this->setlistSelectionStage->run($job);
        } catch (SetlistBudgetExhaustedException $e) {
            throw new GenerationBlockedException($e->getMessage(), BlockedReason::SetlistfmBudget, $e->budgetResetAt, PipelineStage::SetlistSelection, $e);
        }
        $stageTimings['selection'] = self::elapsedMs($stageStart);

        if (null === $playlist) {
            // T-04 (Normal mode, docs/specs/2026-08-25-playlist-normal-mode.md): the stage already
            // suspended the job for a setlist choice — a return-value check, not a mode check
            // (AC-7.2). Nothing left to do until `App\Service\Playlist\Choice\SetlistChoiceApplier`
            // resumes it.
            return;
        }

        if (0 === $job->getSongsTotal()) {
            // T-10: no band on the lineup had a usable setlist. No provider playlist is ever
            // created (D-135) — the row above exists only to carry the per-band report.
            $job->freezeCounters(0, 0, 0, 0, 0, null, null, $stageTimings, ResultKind::NoSourceMaterial, \DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->stateMachine->complete($job);

            return;
        }

        if (JobState::ResolvingSetlist === $job->getState()) {
            // A fresh pass (Fast mode always, Normal mode's first pass). A Normal-mode job resumed
            // after `SubmitSetlistChoiceProcessor` already transitioned to `matching` itself (T-05) —
            // re-entering here would be an illegal `matching -> matching` edge, so this is a state
            // check, not a mode check (AC-7.2).
            $this->stateMachine->enterMatching($job);
        }

        $provider = $this->locator->get($job->getProviderKey());

        $this->assertProviderAvailable($job, PipelineStage::Matching);
        $tokens = $this->usableTokens($job, PipelineStage::Matching);

        $stageStart = microtime(true);
        $choiceDigestsByOrdinal = $this->matchingStage->run($job, $playlist, $provider, $tokens);
        $stageTimings['matching'] = self::elapsedMs($stageStart);

        $hits = self::countHits($playlist);

        if (0 === $hits) {
            // T-09: zero tracks resolved. No provider playlist is created (D-135).
            $this->finishReport($job, $playlist, $stageTimings);

            return;
        }

        if ($this->reviewStage->run($job, $playlist, $choiceDigestsByOrdinal)) {
            // T-07 (Normal mode): the stage already suspended the job for version choices — a
            // boolean check, not a mode check (AC-7.2).
            return;
        }

        $this->assertProviderAvailable($job, PipelineStage::Creation);
        if (JobState::Matching === $job->getState()) {
            // Symmetric to the `enterMatching` guard above: a Normal-mode job resumed after
            // `SubmitVersionChoiceProcessor` is already `building` by the time this line runs.
            $this->stateMachine->enterBuilding($job);
        }

        $stageStart = microtime(true);
        $tokens = $this->usableTokens($job, PipelineStage::Creation);
        $description = $this->namer->description($job->getConcert(), $job->getId() ?? 0, $hits, $job->getSongsTotal());

        try {
            $this->creationStage->run($playlist, $description, $provider, $tokens);
        } catch (PlaylistCreationIndeterminateException $e) {
            $this->stateMachine->fail($job, FailureReason::CreationIndeterminate, ['message' => $e->getMessage()]);

            return;
        }
        $stageTimings['create'] = self::elapsedMs($stageStart);

        $this->assertProviderAvailable($job, PipelineStage::Insertion);
        $tokens = $this->usableTokens($job, PipelineStage::Insertion);

        $stageStart = microtime(true);
        $this->insertionStage->run($playlist, $job->getProviderKey(), $job->getAlgorithmVersion(), $provider, $tokens);
        $stageTimings['insert'] = self::elapsedMs($stageStart);

        $this->finishReport($job, $playlist, $stageTimings);
    }

    /** @param array<string, int> $stageTimings */
    private function finishReport(PlaylistGenerationJob $job, Playlist $playlist, array $stageTimings): void
    {
        $stageStart = microtime(true);
        $this->reportStage->run($job, $playlist, $stageTimings);
        $stageTimings['report'] = self::elapsedMs($stageStart);
    }

    private function assertProviderAvailable(PlaylistGenerationJob $job, PipelineStage $stage): void
    {
        if (!$this->providerAvailability->isAvailable($job->getProviderKey())) {
            $resumableAfter = \DateTimeImmutable::createFromInterface($this->clock->now())->modify('+30 minutes');

            throw new GenerationBlockedException(\sprintf('Provider "%s" became disabled mid-run.', $job->getProviderKey()), BlockedReason::ProviderDisabled, $resumableAfter, $stage);
        }
    }

    private function usableTokens(PlaylistGenerationJob $job, PipelineStage $stage): ProviderTokens
    {
        try {
            return $this->tokenManager->usableTokens($job->getStreamingAccount());
        } catch (TokenExpiredException $e) {
            throw new GenerationBlockedException('Streaming account token expired.', BlockedReason::NeedsReauth, null, $stage, $e);
        } catch (ProviderDisabledException $e) {
            $resumableAfter = \DateTimeImmutable::createFromInterface($this->clock->now())->modify('+30 minutes');

            throw new GenerationBlockedException($e->getMessage(), BlockedReason::ProviderDisabled, $resumableAfter, $stage, $e);
        }
    }

    private static function countHits(Playlist $playlist): int
    {
        $count = 0;
        foreach ($playlist->getTracks() as $track) {
            if ($track->getOutcome()->isHit()) {
                ++$count;
            }
        }

        return $count;
    }

    private static function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
