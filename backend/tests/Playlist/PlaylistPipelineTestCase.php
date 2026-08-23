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
use App\Service\Playlist\PlaylistPipeline;
use App\Service\Provider\PlaybackMode;
use App\Service\Provider\ProviderRegistry;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Shared scaffolding for the playlist-14 test-scope backfill (docs/prompts/14, spec 14 §8,
 * spec 13 §4/§5/§9/§10) — copies `PlaylistPipelineHappyPathTest`'s and
 * `PlaylistPipelineDegradedOutcomesTest`'s truncation/provider-setting/entity-construction shape
 * exactly, so every new test file in this directory behaves identically to the two existing ones
 * with respect to shared-table safety (project memory: never truncate migration-seeded tables).
 */
abstract class PlaylistPipelineTestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();

        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement('TRUNCATE playlist_tracks, playlists, playlist_generation_jobs, track_resolutions RESTART IDENTITY CASCADE');
        $this->entityManager()->clear();

        $this->ensureTestDoubleProviderSetting(enabled: true);
        $this->testDoubleProvider()->reset();
    }

    protected function tearDown(): void
    {
        $this->ensureTestDoubleProviderSetting(enabled: true);
        parent::tearDown();
    }

    protected function ensureTestDoubleProviderSetting(bool $enabled): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();
        $repository = $em->getRepository(ProviderSetting::class);
        $existing = $repository->findOneBy(['provider' => TestDoubleStreamingProvider::KEY]);

        if (null === $existing) {
            $em->persist(new ProviderSetting(TestDoubleStreamingProvider::KEY, $enabled, PlaybackMode::Off, false, null, $now));
        } elseif ($existing->isEnabled() !== $enabled) {
            $existing->setEnabled($enabled);
            $existing->touch($now);
        }
        $em->flush();

        // Harmless, non-destructive: only clears the cache KEY, never any table.
        $redis = self::getContainer()->get('provider.redis');
        $redis->del(ProviderRegistry::CACHE_KEY);
    }

    protected function newUser(string $label): User
    {
        return new User(\sprintf('fan-%s@example.com', uniqid($label.'-', true)), 'hash');
    }

    protected function newBand(string $label, \DateTimeImmutable $now): Band
    {
        $unique = uniqid($label.'-', true);

        return new Band(\sprintf('%s %s', $label, $unique), \sprintf('%s %s', strtolower($label), $unique), $now);
    }

    protected function newConcert(User $user, \DateTimeImmutable $now): Concert
    {
        return new Concert($user, $now, 'Europe/Madrid', $now, $now);
    }

    protected function newStreamingAccount(User $user, \DateTimeImmutable $now): StreamingAccount
    {
        return new StreamingAccount($user, TestDoubleStreamingProvider::KEY, 'token', null, null, [], 'acct-'.uniqid('', true), null, $now);
    }

    /**
     * Persists a cached `Setlist` with `$titles` as its songs, positioned in order, and returns it.
     *
     * @param list<string> $titles
     */
    protected function newCachedSetlist(Band $band, \DateTimeImmutable $eventDate, array $titles, \DateTimeImmutable $now): Setlist
    {
        $setlist = new Setlist('sl-'.uniqid('', true), $band, $eventDate, 'Razzmatazz', 'Barcelona', 'ES', null, $now);
        $this->entityManager()->persist($setlist);

        foreach ($titles as $index => $title) {
            $song = new Song($setlist, $index, null, $title, null, null, null, null, false);
            $setlist->addSong($song);
            $this->entityManager()->persist($song);
        }

        return $setlist;
    }

    protected function newJob(User $user, Concert $concert, StreamingAccount $account, \DateTimeImmutable $now, string $seed = 'a'): PlaylistGenerationJob
    {
        return new PlaylistGenerationJob($user, $concert, TestDoubleStreamingProvider::KEY, $account, JobMode::Fast, str_repeat($seed, 64), 1, $now);
    }

    protected function testDoubleProvider(): TestDoubleStreamingProvider
    {
        $provider = self::getContainer()->get(TestDoubleStreamingProvider::class);
        self::assertInstanceOf(TestDoubleStreamingProvider::class, $provider);

        return $provider;
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function jobRepository(): PlaylistGenerationJobRepository
    {
        return self::getContainer()->get(PlaylistGenerationJobRepository::class);
    }

    protected function playlistRepository(): PlaylistRepository
    {
        return self::getContainer()->get(PlaylistRepository::class);
    }

    protected function pipeline(): PlaylistPipeline
    {
        return self::getContainer()->get(PlaylistPipeline::class);
    }
}
