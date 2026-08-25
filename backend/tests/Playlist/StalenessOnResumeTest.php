<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Entity\PlaylistTrack;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\JobMode;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\TrackOutcome;
use App\Service\Playlist\Stage\MatchingStage;
use App\Service\Playlist\Stage\PreflightStage;
use App\Service\Playlist\Stage\SetlistSelectionStage;
use App\Service\Streaming\Link\StreamingTokenManager;
use App\Service\Streaming\StreamingProviderLocator;

/**
 * Wires the 3 rows of spec 13 §6's staleness table that were previously unit-tested only in
 * isolation on `Choice\StalenessReconciler` but never actually exercised through a resumed job
 * (docs/specs/2026-08-25-playlist-normal-mode.md, AC-8.1/AC-8.2/AC-8.3): the setlist corrected since
 * selection, `algorithmVersion` bumped while the job slept, and the chosen setlist purged from
 * cache. Every test drives the real stages directly for the FIRST pass — exactly
 * `PlaylistPipelineIdempotentRetryTest::buildMatchedPlaylistWithoutCreating()`'s shape — so the job
 * is left mid-flight (`matching`, not `completed`) the way a genuinely interrupted job would be, then
 * hands it to the real `PlaylistPipeline::run()` exactly once for the "resume" — proving both the
 * report code AND that the resumed job reaches a non-`failed` terminal state (AC-8.2).
 */
final class StalenessOnResumeTest extends PlaylistPipelineTestCase
{
    public function testCorrectedSetlistRematchesOnlyTheChangedSongAndKeepsTheRest(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('corrected');
        $em->persist($user);
        $band = $this->newBand('Correction Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $setlist = $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), ['Song One', 'Song Two', 'Song Three'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $setlistId = $setlist->getId();
        self::assertNotNull($setlistId);

        $job = $this->newJob($user, $concert, $account, $now, 'c');
        $this->jobRepository()->save($job);

        [$playlist, $provider, $tokens] = $this->driveToMatching($job);

        // Pass 1: an ordinary first attempt — all 3 songs resolve normally.
        self::getContainer()->get(MatchingStage::class)->run($job, $playlist, $provider, $tokens);
        self::assertSame(3, $this->testDoubleProvider()->getSearchTrackCallCount());

        $beforeCorrection = [];
        foreach ($playlist->getTracks() as $track) {
            $beforeCorrection[$track->getOrdinal()] = $track->getProviderTrackId();
        }

        // setlist.fm corrects "Song Two" (position 1) between selection and resume. D-59 makes a
        // cached Setlist immutable to normal application code, so this simulates whatever external
        // mechanism produced that state rather than going through the (deliberately absent) entity API.
        $em->getConnection()->executeStatement(
            'UPDATE songs SET title = ? WHERE setlist_id = ? AND position = ?',
            ['Song Two (Corrected)', $setlistId, 1],
        );
        $em->clear();

        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);

        // The resume: one real PlaylistPipeline::run() call, same shape as a genuine `blocked ->
        // queued` or `awaiting_*_choice -> matching` re-entry (AC-8.3: the fingerprint recompute
        // happens HERE, at resume, never at a submission handler).
        $this->pipeline()->run($reloadedJob);

        self::assertSame(4, $this->testDoubleProvider()->getSearchTrackCallCount(), 'Only the ONE corrected song should be re-searched — the other two must be left alone.');

        self::assertSame(JobState::Completed, $reloadedJob->getState());

        $reloadedPlaylist = $this->playlistRepository()->findOneBy(['job' => $reloadedJob]);
        self::assertNotNull($reloadedPlaylist);

        $reportCodes = array_column($reloadedPlaylist->getReportSummary(), 'code');
        self::assertContains(ReportCode::SetlistCorrectedSinceSelection->value, $reportCodes);

        self::assertCount(3, $reloadedPlaylist->getTracks(), 'No row should be added or dropped by a title correction.');

        foreach ($reloadedPlaylist->getTracks() as $track) {
            self::assertTrue($track->getOutcome()->isHit(), \sprintf('ordinal %d should have resolved.', $track->getOrdinal()));

            if (1 === $track->getSourcePosition()) {
                self::assertSame('Song Two (Corrected)', $track->getSourceTitle());
            } else {
                self::assertSame($beforeCorrection[$track->getOrdinal()], $track->getProviderTrackId(), 'An unchanged song must keep exactly what it already resolved to.');
            }
        }
    }

    public function testAlgorithmVersionBumpRescoresOnlyPendingSongsAndKeepsExplicitChoices(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('bumped');
        $em->persist($user);
        $band = $this->newBand('Version Bump Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), ['Song One', 'Song Two'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        // Created under an algorithm version older than whatever `matching.algorithm_version` is
        // compiled to in this container (config/matching/profiles.yaml) — the exact shape of "the
        // job slept through a calibration bump".
        $job = new PlaylistGenerationJob($user, $concert, $this->testDoubleProvider()->key(), $account, JobMode::Fast, str_repeat('v', 64), 0, $now);
        $this->jobRepository()->save($job);

        [$playlist] = $this->driveToMatching($job);

        // Simulate a decision already made before the bump — a human decision outranks a formula
        // (spec 13 §6 row 2) — never touched by the reconciliation or by the matcher again.
        $preexisting = self::firstTrackByPosition($playlist, 0);
        $preexisting->resolve(TrackOutcome::Matched, 'preexisting-track-id', 0.95, null, []);
        $em->flush();

        $this->pipeline()->run($job);

        self::assertSame(JobState::Completed, $job->getState());
        self::assertSame(1, $this->testDoubleProvider()->getSearchTrackCallCount(), 'The preexisting choice must never be re-searched.');

        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertNotSame(0, $reloadedJob->getAlgorithmVersion(), 'The stale version must be replaced by the current one.');

        $reloadedPlaylist = $this->playlistRepository()->findOneBy(['job' => $reloadedJob]);
        self::assertNotNull($reloadedPlaylist);

        $reportEntries = $reloadedPlaylist->getReportSummary();
        $bumpEntry = null;
        foreach ($reportEntries as $entry) {
            if (ReportCode::RescoredAfterAlgorithmUpdate->value === $entry['code']) {
                $bumpEntry = $entry;
            }
        }
        self::assertNotNull($bumpEntry, 'RESCORED_AFTER_ALGORITHM_UPDATE must be reported.');
        self::assertSame(1, $bumpEntry['params']['songsAffected'], 'Only the ONE still-pending song counts — the preexisting choice does not.');

        $keptTrack = self::firstTrackByPosition($reloadedPlaylist, 0);
        self::assertSame('preexisting-track-id', $keptTrack->getProviderTrackId());

        $rescoredTrack = self::firstTrackByPosition($reloadedPlaylist, 1);
        self::assertTrue($rescoredTrack->getOutcome()->isHit());
    }

    public function testPurgedSelectedSetlistFallsBackToAutomaticSelection(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('purged');
        $em->persist($user);
        $band = $this->newBand('Cache Purge Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);

        // The fallback candidate: older, shorter, but the ONLY one left once the primary is purged.
        $this->newCachedSetlist($band, new \DateTimeImmutable('2020-01-01'), ['Fallback One', 'Fallback Two'], $now);
        // The primary candidate: newer and longer, so it wins D-132's "most recent substantial" (via
        // its longest-in-window fallback, since neither clears the substantial threshold on its own).
        $primary = $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), ['Song One', 'Song Two', 'Song Three'], $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $primaryId = $primary->getId();
        self::assertNotNull($primaryId);

        $job = $this->newJob($user, $concert, $account, $now, 'p');
        $this->jobRepository()->save($job);

        [$playlist] = $this->driveToMatching($job);
        self::assertCount(3, $playlist->getTracks());

        // The cache purge: the whole row is gone, cascading its Songs and (via `ON DELETE SET NULL`)
        // orphaning the already-built PlaylistTrack rows.
        $primaryEntity = $em->getRepository(\App\Entity\Setlist::class)->find($primaryId);
        self::assertNotNull($primaryEntity);
        $em->remove($primaryEntity);
        $em->flush();
        $em->clear();

        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);

        $this->pipeline()->run($reloadedJob);

        self::assertSame(JobState::Completed, $reloadedJob->getState(), 'A purged cache entry must never fail the job (AC-8.2).');
        self::assertSame(2, $reloadedJob->getSongsTotal());

        $reloadedPlaylist = $this->playlistRepository()->findOneBy(['job' => $reloadedJob]);
        self::assertNotNull($reloadedPlaylist);

        $reportCodes = array_column($reloadedPlaylist->getReportSummary(), 'code');
        self::assertContains(ReportCode::SelectedSetlistUnavailable->value, $reportCodes);

        $titles = [];
        foreach ($reloadedPlaylist->getTracks() as $track) {
            $titles[] = $track->getSourceTitle();
            self::assertTrue($track->getOutcome()->isHit());
        }
        sort($titles);
        self::assertSame(['Fallback One', 'Fallback Two'], $titles);
    }

    /**
     * Drives `PreflightStage` -> `SetlistSelectionStage` -> `enterMatching`, mirroring
     * `PlaylistPipelineIdempotentRetryTest::buildMatchedPlaylistWithoutCreating()` — the shape of a
     * job stopped right before matching, ready either for a direct `MatchingStage::run()` call or a
     * real resume through `PlaylistPipeline::run()`.
     *
     * @return array{0: \App\Entity\Playlist, 1: \App\Service\Streaming\StreamingProviderInterface, 2: \App\Service\Streaming\Model\ProviderTokens}
     */
    private function driveToMatching(PlaylistGenerationJob $job): array
    {
        self::getContainer()->get(PreflightStage::class)->run($job);
        $playlist = self::getContainer()->get(SetlistSelectionStage::class)->run($job);
        self::assertNotNull($playlist);
        self::getContainer()->get(JobStateMachine::class)->enterMatching($job);
        $provider = self::getContainer()->get(StreamingProviderLocator::class)->get($job->getProviderKey());
        $tokens = self::getContainer()->get(StreamingTokenManager::class)->usableTokens($job->getStreamingAccount());

        return [$playlist, $provider, $tokens];
    }

    private static function firstTrackByPosition(\App\Entity\Playlist $playlist, int $sourcePosition): PlaylistTrack
    {
        foreach ($playlist->getTracks() as $track) {
            if ($sourcePosition === $track->getSourcePosition()) {
                return $track;
            }
        }

        self::fail(\sprintf('No track at sourcePosition %d.', $sourcePosition));
    }
}
