<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\Band;
use App\Entity\Concert;
use App\Entity\PlaylistGenerationJob;
use App\Entity\ProviderSetting;
use App\Entity\Setlist;
use App\Entity\Song;
use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Repository\PlaylistGenerationJobRepository;
use App\Repository\PlaylistRepository;
use App\Service\Playlist\Model\JobMode;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ResultKind;
use App\Service\Playlist\Model\TrackOutcome;
use App\Service\Playlist\PlaylistPipeline;
use App\Service\Provider\PlaybackMode;
use App\Service\Provider\ProviderRegistry;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * T-INT-01 (happy path) and T-INT-06/T-INT-18-adjacent checks, run against the real pipeline, the
 * real database, and `TestDoubleStreamingProvider` (spec 14 §8's fixture shape) — no outbound HTTP
 * call is made anywhere in this test, per D-2/D-70/D-85. setlist.fm is faked at the data layer
 * (cached `Setlist`/`Song` rows persisted directly), so `SetlistGatewayIsOnlyDoorTest` stays green.
 */
final class PlaylistPipelineHappyPathTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();

        // Only feature-owned tables are truncated — nothing else in the app writes to these yet.
        // Shared tables (users, bands, concerts, streaming_accounts, provider_settings — the last
        // seeded by a migration, D-102) are never touched: truncating them would delete fixture
        // data other test suites depend on for the rest of the run.
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement('TRUNCATE playlist_tracks, playlists, playlist_generation_jobs, track_resolutions RESTART IDENTITY CASCADE');
        $this->entityManager()->clear();
    }

    public function testAWellCoveredBandProducesACompletePlaylistInSetlistOrder(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();
        $unique = uniqid('happy-', true);

        $user = new User(\sprintf('fan-%s@example.com', $unique), 'hash');
        $em->persist($user);

        $band = new Band(\sprintf('The Testers %s', $unique), \sprintf('the testers %s', $unique), $now);
        $em->persist($band);

        $concert = new Concert($user, $now, 'Europe/Madrid', $now, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);

        $setlist = new Setlist('sl-'.$unique, $band, new \DateTimeImmutable('2023-07-12'), 'Razzmatazz', 'Barcelona', 'ES', null, $now);
        $em->persist($setlist);
        foreach (['Song One', 'Song Two', 'Song Three'] as $index => $title) {
            $song = new Song($setlist, $index, null, $title, null, null, null, null, false);
            $setlist->addSong($song);
            $em->persist($song);
        }

        $account = new StreamingAccount($user, TestDoubleStreamingProvider::KEY, 'token', null, null, [], 'acct-'.$unique, null, $now);
        $em->persist($account);

        $this->ensureTestDoubleProviderSetting($now);

        $em->flush();

        $job = new PlaylistGenerationJob($user, $concert, TestDoubleStreamingProvider::KEY, $account, JobMode::Fast, str_repeat('a', 64), 1, $now);
        $this->jobRepository()->save($job);

        $this->pipeline()->run($job);

        $em->clear();
        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertSame(JobState::Completed, $reloadedJob->getState());
        self::assertSame(ResultKind::Complete, $reloadedJob->getResultKind());
        self::assertSame(3, $reloadedJob->getMatchedCount());
        self::assertSame(0, $reloadedJob->getNotFoundCount());

        $playlist = $this->playlistRepository()->findOneBy(['job' => $reloadedJob]);
        self::assertNotNull($playlist);
        self::assertSame('double-playlist-1', $playlist->getProviderPlaylistId());
        self::assertNotNull($playlist->getCreationAttemptedAt());

        $tracks = $playlist->getTracks();
        self::assertCount(3, $tracks);
        $ordinals = [];
        foreach ($tracks as $track) {
            self::assertSame(TrackOutcome::Matched, $track->getOutcome());
            self::assertSame('double-track-1', $track->getProviderTrackId());
            self::assertNotNull($track->getInsertedAt());
            $ordinals[] = $track->getOrdinal();
        }
        self::assertSame([0, 1, 2], $ordinals);
    }

    /**
     * The `test-double` provider's settings row is shared, cross-test, config data — not
     * per-test fixture data — so it is created once and reused, never truncated or duplicated
     * (`is_default` must stay `false`: only one row in the whole table may be the default, and
     * `spotify`, seeded by migration D-102, already holds that slot).
     */
    private function ensureTestDoubleProviderSetting(\DateTimeImmutable $now): void
    {
        $em = $this->entityManager();
        $repository = $em->getRepository(ProviderSetting::class);
        $existing = $repository->findOneBy(['provider' => TestDoubleStreamingProvider::KEY]);

        if (null === $existing) {
            $em->persist(new ProviderSetting(TestDoubleStreamingProvider::KEY, true, PlaybackMode::Off, false, null, $now));
            $em->flush();
        }

        // Harmless, non-destructive: only clears the cache KEY (ProviderRegistry rebuilds it from
        // the database on the next read), never any table — safe even when another test populated
        // this same key first, without `test-double` in it (e.g. before this row existed).
        $redis = self::getContainer()->get('provider.redis');
        $redis->del(ProviderRegistry::CACHE_KEY);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function jobRepository(): PlaylistGenerationJobRepository
    {
        return self::getContainer()->get(PlaylistGenerationJobRepository::class);
    }

    private function playlistRepository(): PlaylistRepository
    {
        return self::getContainer()->get(PlaylistRepository::class);
    }

    private function pipeline(): PlaylistPipeline
    {
        return self::getContainer()->get(PlaylistPipeline::class);
    }
}
