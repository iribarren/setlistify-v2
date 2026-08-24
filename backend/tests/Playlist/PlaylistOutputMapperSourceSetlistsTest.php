<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\Playlist;
use App\Entity\PlaylistTrack;
use App\Entity\Setlist;
use App\Service\Playlist\Model\SelectionReason;
use App\State\PlaylistOutputMapper;

/**
 * T-5/T-6: `PlaylistOutputMapper::map()` groups the tracks it already iterates into `sourceSetlists`
 * — one band; two bands in first-appearance order; duplicate ids collapse; zero tracks -> `[]`; and
 * the ids agree with `PlaylistGenerationJob::$selectedSetlists` when the latter is present.
 */
final class PlaylistOutputMapperSourceSetlistsTest extends PlaylistPipelineTestCase
{
    public function testOneBandProducesOneSourceSetlistEntry(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->newUser('one-band');
        $band = $this->newBand('Solo Band', $now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $this->entityManager()->persist($concert);
        $account = $this->newStreamingAccount($user, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $setlist = $this->newCachedSetlist($band, $now, ['Song A', 'Song B'], $now);
        $this->entityManager()->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'a');
        $this->jobRepository()->save($job);
        $playlist = new Playlist($user, $concert, $job, 'test-double', 'Test Playlist', $now);
        $this->addTracks($playlist, $band, $setlist);
        $this->entityManager()->persist($playlist);
        $this->entityManager()->flush();

        $output = $this->mapper()->map($playlist);

        self::assertCount(1, $output->sourceSetlists);
        self::assertSame($band->getName(), $output->sourceSetlists[0]->bandName);
        self::assertSame($setlist->getSetlistfmId(), $output->sourceSetlists[0]->setlistfmId);
    }

    public function testTwoBandsAppearInFirstAppearancePlayingOrder(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->newUser('two-bands');
        $support = $this->newBand('Support Band', $now);
        $headliner = $this->newBand('Headliner Band', $now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($support);
        $this->entityManager()->persist($headliner);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($headliner, 0);
        $concert->addLineupEntry($support, 1);
        $this->entityManager()->persist($concert);
        $account = $this->newStreamingAccount($user, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $supportSetlist = $this->newCachedSetlist($support, $now, ['Support Song'], $now);
        $headlinerSetlist = $this->newCachedSetlist($headliner, $now, ['Headliner Song'], $now);
        $this->entityManager()->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'b');
        $this->jobRepository()->save($job);
        $playlist = new Playlist($user, $concert, $job, 'test-double', 'Test Playlist', $now);
        // Playing order: support act first, headliner last (stage order).
        $this->addTracks($playlist, $support, $supportSetlist);
        $this->addTracks($playlist, $headliner, $headlinerSetlist);
        $this->entityManager()->persist($playlist);
        $this->entityManager()->flush();

        $output = $this->mapper()->map($playlist);

        self::assertCount(2, $output->sourceSetlists);
        self::assertSame($support->getName(), $output->sourceSetlists[0]->bandName);
        self::assertSame($headliner->getName(), $output->sourceSetlists[1]->bandName);
    }

    public function testDuplicateSetlistIdsAcrossTracksCollapseToOneEntry(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->newUser('dup');
        $band = $this->newBand('Dup Band', $now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $this->entityManager()->persist($concert);
        $account = $this->newStreamingAccount($user, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $setlist = $this->newCachedSetlist($band, $now, ['Song A', 'Song B', 'Song C'], $now);
        $this->entityManager()->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'c');
        $this->jobRepository()->save($job);
        $playlist = new Playlist($user, $concert, $job, 'test-double', 'Test Playlist', $now);
        $this->addTracks($playlist, $band, $setlist);
        $this->entityManager()->persist($playlist);
        $this->entityManager()->flush();

        $output = $this->mapper()->map($playlist);

        self::assertCount(1, $output->sourceSetlists, 'every track from the same setlist must collapse into one entry.');
    }

    public function testZeroTracksProducesAnEmptyListNeverNull(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->newUser('zero');
        $band = $this->newBand('Zero Band', $now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $this->entityManager()->persist($concert);
        $account = $this->newStreamingAccount($user, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'z');
        $this->jobRepository()->save($job);
        $playlist = new Playlist($user, $concert, $job, 'test-double', 'Test Playlist', $now);
        $this->entityManager()->persist($playlist);
        $this->entityManager()->flush();

        $output = $this->mapper()->map($playlist);

        self::assertSame([], $output->sourceSetlists);
    }

    public function testSourceSetlistIdsAgreeWithTheJobsSelectedSetlistsWhenPresent(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->newUser('agree');
        $band = $this->newBand('Agree Band', $now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $this->entityManager()->persist($concert);
        $account = $this->newStreamingAccount($user, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $setlist = $this->newCachedSetlist($band, $now, ['Song A'], $now);
        $this->entityManager()->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'd');
        $job->setSelectedSetlists([[
            'bandId' => $band->getId() ?? 0,
            'setlistfmId' => $setlist->getSetlistfmId(),
            'selectionReason' => SelectionReason::OnlyOneAvailable->value,
            'fingerprint' => $setlist->getSetlistfmId(),
            'songCount' => 1,
        ]]);
        $this->jobRepository()->save($job);
        $playlist = new Playlist($user, $concert, $job, 'test-double', 'Test Playlist', $now);
        $this->addTracks($playlist, $band, $setlist);
        $this->entityManager()->persist($playlist);
        $this->entityManager()->flush();

        $output = $this->mapper()->map($playlist);

        $selected = $job->getSelectedSetlists();
        self::assertNotNull($selected);
        self::assertSame($selected[0]['setlistfmId'], $output->sourceSetlists[0]->setlistfmId);
    }

    public function testANullSelectedSetlistsDoesNotAffectTheOutput(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->newUser('null-selected');
        $band = $this->newBand('Null Selected Band', $now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $this->entityManager()->persist($concert);
        $account = $this->newStreamingAccount($user, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $setlist = $this->newCachedSetlist($band, $now, ['Song A'], $now);
        $this->entityManager()->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'e');
        self::assertNull($job->getSelectedSetlists());
        $this->jobRepository()->save($job);
        $playlist = new Playlist($user, $concert, $job, 'test-double', 'Test Playlist', $now);
        $this->addTracks($playlist, $band, $setlist);
        $this->entityManager()->persist($playlist);
        $this->entityManager()->flush();

        $output = $this->mapper()->map($playlist);

        self::assertCount(1, $output->sourceSetlists);
    }

    private function addTracks(Playlist $playlist, \App\Entity\Band $band, Setlist $setlist): void
    {
        $ordinal = $playlist->getTracks()->count();
        foreach ($setlist->getSongs() as $song) {
            $track = new PlaylistTrack(
                $playlist,
                $ordinal++,
                $song,
                $band,
                $setlist->getSetlistfmId(),
                $song->getPosition(),
                $song->getTitle(),
            );
            $playlist->addTrack($track);
        }
    }

    private function mapper(): PlaylistOutputMapper
    {
        return static::getContainer()->get(PlaylistOutputMapper::class);
    }
}
