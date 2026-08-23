<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\ResultKind;
use App\Service\Playlist\Model\TrackOutcome;

/**
 * AC-4, T-INT-07 (spec 14 §8, D-140): setlist order survives a miss in the MIDDLE of the list, not
 * just at the end — `sourcePosition` still records the gap while the provider insert sequence
 * equals the matched subsequence of source order (the divergence between `ordinal`/`sourcePosition`
 * IS the report).
 */
final class PlaylistPipelineOrderingTest extends PlaylistPipelineTestCase
{
    public function testAMissInTheMiddleLeavesItsNeighboursAdjacentInTheInsertSequenceWhilePreservingSourcePosition(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('order');
        $em->persist($user);
        $band = $this->newBand('Order Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);

        $titles = ['Song One', 'Song Two', 'Missing Song', 'Song Four', 'Song Five'];
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), $titles, $now);
        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'o');
        $this->jobRepository()->save($job);

        $provider = $this->testDoubleProvider();
        $provider->scriptNoCandidates('Missing Song');
        $provider->scriptTrackId('Song One', 'trk-1');
        $provider->scriptTrackId('Song Two', 'trk-2');
        $provider->scriptTrackId('Song Four', 'trk-4');
        $provider->scriptTrackId('Song Five', 'trk-5');

        $this->pipeline()->run($job);

        $this->entityManager()->clear();
        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertSame(JobState::Completed, $reloadedJob->getState());
        self::assertSame(ResultKind::Partial, $reloadedJob->getResultKind());
        self::assertSame(4, $reloadedJob->getMatchedCount());
        self::assertSame(1, $reloadedJob->getNotFoundCount());

        $playlist = $this->playlistRepository()->findOneBy(['job' => $reloadedJob]);
        self::assertNotNull($playlist);

        $tracks = $playlist->getTracks()->toArray();
        self::assertCount(5, $tracks, 'Every source song gets a row, including the missing one (D-139).');

        // Ordinal AND sourcePosition are both dense 0..4 here (D-139/D-140: the skeleton is created
        // up front, one row per source song, so the row for the miss stays at its place).
        $bySourcePosition = [];
        foreach ($tracks as $track) {
            $bySourcePosition[$track->getSourcePosition()] = $track;
        }
        ksort($bySourcePosition);
        self::assertSame([0, 1, 2, 3, 4], array_keys($bySourcePosition));

        self::assertSame(TrackOutcome::NotFound, $bySourcePosition[2]->getOutcome());
        self::assertSame(ReportCode::TrackNotInCatalog, $bySourcePosition[2]->getReasonCode());
        self::assertNull($bySourcePosition[2]->getInsertedAt());

        foreach ([0, 1, 3, 4] as $position) {
            self::assertSame(TrackOutcome::Matched, $bySourcePosition[$position]->getOutcome());
            self::assertNotNull($bySourcePosition[$position]->getInsertedAt());
        }

        // The actual provider insert sequence is the matched subsequence of source order —
        // trk-1, trk-2, (gap), trk-4, trk-5 — with the miss simply absent, not replaced by a
        // placeholder and not reordering its neighbours.
        $insertedIds = array_merge(...$provider->getAddTracksCallLog());
        self::assertSame(['trk-1', 'trk-2', 'trk-4', 'trk-5'], $insertedIds);
    }
}
