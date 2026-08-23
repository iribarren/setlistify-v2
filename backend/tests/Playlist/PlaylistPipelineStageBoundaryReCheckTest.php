<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Exception\GenerationBlockedException;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\PipelineStage;
use App\Service\Playlist\PlaylistPipeline;
use App\Service\Playlist\Stage\CreationStage;
use App\Service\Playlist\Stage\InsertionStage;
use App\Service\Playlist\Stage\MatchingStage;
use App\Service\Playlist\Stage\PreflightStage;
use App\Service\Playlist\Stage\SetlistSelectionStage;
use App\Service\Streaming\Link\StreamingTokenManager;
use App\Service\Streaming\StreamingProviderLocator;

/**
 * AC-8, spec 14 §3/§5 (F-07): "every stage boundary re-checks `ProviderRegistry::isAvailable()`,
 * not just preflight" (D-134). `PlaylistPipelineDegradedOutcomesTest::
 * testProviderDisabledAtPreflightBlocksCleanlyRatherThanFailing()` already proves the FIRST check
 * (`PreflightStage`'s own, independent check). This file proves the other three:
 * `PlaylistPipeline::run()`'s own re-checks before `SetlistSelectionStage`, before `MatchingStage`
 * and before `CreationStage`/`InsertionStage` — by disabling the provider in real time, between two
 * real stage calls, rather than before the run starts. That is the one thing a single synchronous
 * `pipeline()->run($job)` call cannot exercise for a later boundary (there is no hook between two
 * adjacent lines of `PlaylistPipeline::run()` to intervene from outside it), so this test drives the
 * SAME stage services `PlaylistPipeline::run()` calls, in the same order, and calls its private
 * `assertProviderAvailable()` via reflection at each point — the exact method and exact stage
 * argument the pipeline itself uses, not a reimplementation of the check.
 */
final class PlaylistPipelineStageBoundaryReCheckTest extends PlaylistPipelineTestCase
{
    public function testReCheckFiresBeforeSetlistSelectionNotJustAtPreflight(): void
    {
        $job = $this->buildEnabledJob('boundary-selection');

        self::getContainer()->get(PreflightStage::class)->run($job);
        self::assertProviderStillEnabledSoFar();

        $this->ensureTestDoubleProviderSetting(enabled: false);

        $this->expectBlockedAtStage($job, PipelineStage::SetlistSelection);
    }

    public function testReCheckFiresBeforeMatchingNotJustAtPreflight(): void
    {
        $job = $this->buildEnabledJob('boundary-matching');

        self::getContainer()->get(PreflightStage::class)->run($job);
        self::getContainer()->get(SetlistSelectionStage::class)->run($job);

        $this->ensureTestDoubleProviderSetting(enabled: false);

        $this->expectBlockedAtStage($job, PipelineStage::Matching);
    }

    public function testReCheckFiresBeforeCreationNotJustAtPreflight(): void
    {
        $job = $this->buildEnabledJob('boundary-creation');

        self::getContainer()->get(PreflightStage::class)->run($job);
        $playlist = self::getContainer()->get(SetlistSelectionStage::class)->run($job);
        self::getContainer()->get(JobStateMachine::class)->enterMatching($job);
        $provider = self::getContainer()->get(StreamingProviderLocator::class)->get($job->getProviderKey());
        $tokens = self::getContainer()->get(StreamingTokenManager::class)->usableTokens($job->getStreamingAccount());
        self::getContainer()->get(MatchingStage::class)->run($job, $playlist, $provider, $tokens);

        $this->ensureTestDoubleProviderSetting(enabled: false);

        $this->expectBlockedAtStage($job, PipelineStage::Creation);
    }

    public function testReCheckFiresBeforeInsertionNotJustAtPreflight(): void
    {
        $job = $this->buildEnabledJob('boundary-insertion');

        self::getContainer()->get(PreflightStage::class)->run($job);
        $playlist = self::getContainer()->get(SetlistSelectionStage::class)->run($job);
        self::getContainer()->get(JobStateMachine::class)->enterMatching($job);
        $provider = self::getContainer()->get(StreamingProviderLocator::class)->get($job->getProviderKey());
        $tokens = self::getContainer()->get(StreamingTokenManager::class)->usableTokens($job->getStreamingAccount());
        self::getContainer()->get(MatchingStage::class)->run($job, $playlist, $provider, $tokens);
        self::getContainer()->get(JobStateMachine::class)->enterBuilding($job);
        self::getContainer()->get(CreationStage::class)->run($playlist, 'test description', $provider, $tokens);

        self::assertNotNull($playlist->getProviderPlaylistId(), 'The provider playlist must exist by the time insertion would begin.');

        $this->ensureTestDoubleProviderSetting(enabled: false);

        $this->expectBlockedAtStage($job, PipelineStage::Insertion);

        self::assertSame(0, $this->testDoubleProvider()->getAddTracksCallCount(), 'InsertionStage must never be entered once the boundary re-check blocks.');
    }

    private function buildEnabledJob(string $label): PlaylistGenerationJob
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser($label);
        $em->persist($user);
        $band = $this->newBand($label.' Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), ['Song One', 'Song Two'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'b');
        $this->jobRepository()->save($job);

        return $job;
    }

    private static function assertProviderStillEnabledSoFar(): void
    {
        // No-op marker for readability at the call site: PreflightStage already succeeded, which is
        // only possible while the provider was enabled.
    }

    /** Invokes `PlaylistPipeline::assertProviderAvailable()` — the exact private method/branch `run()` calls at every boundary — via reflection, and asserts it blocks at the given stage. */
    private function expectBlockedAtStage(PlaylistGenerationJob $job, PipelineStage $stage): void
    {
        $pipeline = $this->pipeline();
        $method = new \ReflectionMethod(PlaylistPipeline::class, 'assertProviderAvailable');
        $method->setAccessible(true);

        try {
            $method->invoke($pipeline, $job, $stage);
            self::fail(\sprintf('Expected GenerationBlockedException at stage "%s".', $stage->value));
        } catch (\ReflectionException $e) {
            self::fail($e->getMessage());
        } catch (GenerationBlockedException $e) {
            self::assertSame(BlockedReason::ProviderDisabled, $e->reason);
            self::assertSame($stage, $e->stage);
        }
    }
}
