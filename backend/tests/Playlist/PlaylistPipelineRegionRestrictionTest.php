<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\ResultKind;
use App\Service\Playlist\Model\TrackOutcome;

/**
 * AC-3, T-INT-16 (spec 14 §4/§8, F-11): a region-restricted track at insert time is a per-track
 * outcome, never a job failure — the job still reaches `completed`, and the `TrackResolution` row
 * for that song is NOT invalidated (the track itself is still correct, just unavailable to this
 * user's region — spec 13 §4's F-11 row).
 *
 * `addTracks()` is called once per batch of up to `GENERATION_INSERT_BATCH_SIZE` (50) tracks, and
 * `InsertionStage` cannot ask the frozen 9-method port (D-71) which single id inside a batch was the
 * one that failed — a batch-level exception marks the WHOLE batch (`InsertionStage`'s own comment on
 * the equivalent F-13 case says so explicitly). So this fixture uses 51 songs — 50 clean ones in the
 * first batch, the restricted one alone in the second — to isolate the restriction to the one track
 * it actually belongs to, rather than exercising that batching caveat.
 */
final class PlaylistPipelineRegionRestrictionTest extends PlaylistPipelineTestCase
{
    public function testRegionRestrictedTrackProducesAPerTrackOutcomeAndTheJobStillCompletes(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('region');
        $em->persist($user);
        $band = $this->newBand('Region Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);

        $titles = array_map(static fn (int $i): string => \sprintf('Song %d', $i), range(1, 50));
        $titles[] = 'Restricted Song';
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), $titles, $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'g');
        $this->jobRepository()->save($job);

        $this->testDoubleProvider()->scriptTrackId('Restricted Song', 'double-track-restricted');
        $this->testDoubleProvider()->scriptRegionRestrictedTrack('double-track-restricted');

        $this->pipeline()->run($job);

        $this->entityManager()->clear();
        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertSame(JobState::Completed, $reloadedJob->getState(), 'A region-restricted track must never fail the job.');
        self::assertSame(ResultKind::Partial, $reloadedJob->getResultKind());
        self::assertSame(1, $reloadedJob->getRegionRestrictedCount());
        self::assertSame(50, $reloadedJob->getMatchedCount(), 'The other 50 songs, in a separate batch, are unaffected.');

        $playlist = $this->playlistRepository()->findOneBy(['job' => $reloadedJob]);
        self::assertNotNull($playlist);

        $restricted = null;
        foreach ($playlist->getTracks() as $track) {
            if ('Restricted Song' === $track->getSourceTitle()) {
                $restricted = $track;
            }
        }
        self::assertNotNull($restricted, 'The restricted song must still have its own row (D-139).');
        self::assertSame(TrackOutcome::RegionRestricted, $restricted->getOutcome());
        self::assertSame(ReportCode::NotAvailableInRegion, $restricted->getReasonCode());
        self::assertNull($restricted->getInsertedAt(), 'A region-restricted track was never actually inserted at the provider.');

        // The TrackResolution row must survive — the resolution itself was correct, only this
        // user's region blocked it (spec 12 §8's rule, restated in spec 13's F-11 row).
        $resolutionStore = self::getContainer()->get(\App\Service\Matching\Cache\TrackResolutionStore::class);
        $normalizedArtist = \App\Service\Concert\BandResolver::normalize($band->getName());
        $normalizedTitle = self::getContainer()->get(\App\Service\Matching\SongNormalizer::class)->normalize('Restricted Song')->comparisonCore;
        $resolved = $resolutionStore->find($reloadedJob->getProviderKey(), $reloadedJob->getAlgorithmVersion(), $normalizedArtist, $normalizedTitle);
        self::assertNotNull($resolved, 'F-11: the TrackResolution row must NOT be invalidated on a region restriction.');
    }
}
