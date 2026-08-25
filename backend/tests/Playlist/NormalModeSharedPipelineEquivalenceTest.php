<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ResultKind;

/**
 * AC-7.1 (docs/specs/2026-08-25-playlist-normal-mode.md, D-189): a Normal-mode job on a band with
 * exactly one usable setlist and an empty CHOICE band produces a state sequence identical to the
 * Fast-mode job for the same concert, and an identical set of `PlaylistTrack` rows.
 */
final class NormalModeSharedPipelineEquivalenceTest extends NormalModePipelineTestCase
{
    public function testNormalModeWithOneSetlistAndNoAmbiguityMatchesFastMode(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $band = $this->newBand('Equivalence', $now);
        $em->persist($band);
        $eventDate = new \DateTimeImmutable('2023-03-03');

        // Fast-mode job.
        $fastUser = $this->newUser('equiv-fast');
        $em->persist($fastUser);
        $fastConcert = $this->newConcert($fastUser, $now);
        $fastConcert->addLineupEntry($band, 0);
        $em->persist($fastConcert);
        $this->newCachedSetlist($band, $eventDate, ['Song A', 'Song B'], $now);
        $fastAccount = $this->newStreamingAccount($fastUser, $now);
        $em->persist($fastAccount);

        // Normal-mode job, same band, same single setlist, different concert/user (own scope).
        $normalUser = $this->newUser('equiv-normal');
        $em->persist($normalUser);
        $normalConcert = $this->newConcert($normalUser, $now);
        $normalConcert->addLineupEntry($band, 0);
        $em->persist($normalConcert);
        $normalAccount = $this->newStreamingAccount($normalUser, $now);
        $em->persist($normalAccount);

        $em->flush();

        $fastJob = $this->newJob($fastUser, $fastConcert, $fastAccount, $now, 'f');
        $this->jobRepository()->save($fastJob);
        $this->pipeline()->run($fastJob);

        $normalJob = $this->newNormalJob($normalUser, $normalConcert, $normalAccount, $now, 'n');
        $this->jobRepository()->save($normalJob);
        $this->pipeline()->run($normalJob);

        $em->clear();
        $fastReloaded = $this->jobRepository()->find($fastJob->getId());
        $normalReloaded = $this->jobRepository()->find($normalJob->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $fastReloaded);
        self::assertInstanceOf(PlaylistGenerationJob::class, $normalReloaded);

        self::assertSame(JobState::Completed, $fastReloaded->getState());
        self::assertSame(JobState::Completed, $normalReloaded->getState());
        self::assertSame($fastReloaded->getResultKind(), $normalReloaded->getResultKind());
        self::assertSame(ResultKind::Complete, $normalReloaded->getResultKind());
        self::assertSame($fastReloaded->getSongsTotal(), $normalReloaded->getSongsTotal());
        self::assertSame($fastReloaded->getMatchedCount(), $normalReloaded->getMatchedCount());

        // Normal mode never touched either suspension state — the shared-pipeline property.
        self::assertNull($normalReloaded->getCandidateSetlists());
        self::assertNull($normalReloaded->getPendingChoices());

        $fastPlaylist = $this->playlistRepository()->findOneBy(['job' => $fastReloaded]);
        $normalPlaylist = $this->playlistRepository()->findOneBy(['job' => $normalReloaded]);
        self::assertNotNull($fastPlaylist);
        self::assertNotNull($normalPlaylist);

        $describe = static fn ($playlist): array => array_map(
            static fn ($t) => [$t->getSourceTitle(), $t->getOutcome()->value, $t->getProviderTrackId()],
            array_values($playlist->getTracks()->toArray()),
        );

        self::assertSame($describe($fastPlaylist), $describe($normalPlaylist));
    }
}
