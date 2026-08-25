<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\Playlist;
use App\State\PlaylistOutputMapper;

/**
 * D-211/D-212: `PlaylistOutputMapper::map()` computes `embedUrl` and falls back `externalUrl` to
 * the port, never storing or building either on the client. Covers the four degrade-not-fail cases
 * spec 19 §2 names: adapter returns a real embed url, adapter returns null, the provider cannot be
 * resolved (`UnknownProviderException`), and the playlist has no provider-side id yet.
 */
final class PlaylistOutputMapperPlaybackTest extends PlaylistPipelineTestCase
{
    public function testEmbedUrlIsComputedFromThePortWhenCreationCompleted(): void
    {
        $playlist = $this->newCompletedPlaylist('f');

        $output = $this->mapper()->map($playlist);

        self::assertSame('https://double.invalid/embed/double-playlist-f', $output->embedUrl);
        self::assertSame('https://double.invalid/playlists/double-playlist-f', $output->externalUrl);
    }

    public function testEmbedUrlIsNullWhenTheAdapterReturnsNull(): void
    {
        $this->testDoubleProvider()->scriptEmbedUrlNull();
        $playlist = $this->newCompletedPlaylist('g');

        $output = $this->mapper()->map($playlist);

        self::assertNull($output->embedUrl);
        // externalUrl is unaffected — it was already stored at creation time.
        self::assertSame('https://double.invalid/playlists/double-playlist-g', $output->externalUrl);
    }

    public function testEmbedUrlIsNullWhenTheProviderCannotBeResolved(): void
    {
        $playlist = $this->newCompletedPlaylist('h', providerKey: 'retired-provider');

        $output = $this->mapper()->map($playlist);

        self::assertNull($output->embedUrl, 'an unresolvable provider must degrade to null, never throw (CLAUDE.md: degrades, does not fail).');
    }

    public function testEmbedUrlIsNullWhenThePlaylistHasNoProviderSideIdYet(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->newUser('no-id');
        $band = $this->newBand('No Id Band', $now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $this->entityManager()->persist($concert);
        $account = $this->newStreamingAccount($user, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'n');
        $this->jobRepository()->save($job);
        $playlist = new Playlist($user, $concert, $job, 'test-double', 'Test Playlist', $now);
        // Creation never confirmed: no providerPlaylistId, no externalUrl.
        $this->entityManager()->persist($playlist);
        $this->entityManager()->flush();

        $output = $this->mapper()->map($playlist);

        self::assertNull($output->embedUrl);
        self::assertNull($output->externalUrl);
    }

    private function newCompletedPlaylist(string $seed, string $providerKey = 'test-double'): Playlist
    {
        $now = new \DateTimeImmutable();
        $user = $this->newUser($seed);
        $band = $this->newBand($seed.' Band', $now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $this->entityManager()->persist($concert);
        $account = $this->newStreamingAccount($user, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $job = $this->newJob($user, $concert, $account, $now, $seed);
        $this->jobRepository()->save($job);
        $playlist = new Playlist($user, $concert, $job, $providerKey, 'Test Playlist', $now);
        $playlist->markCreationAttempted($now);
        $playlist->confirmCreated(
            'double-playlist-'.$seed,
            \sprintf('https://double.invalid/playlists/double-playlist-%s', $seed),
            $now,
        );
        $this->entityManager()->persist($playlist);
        $this->entityManager()->flush();

        return $playlist;
    }

    private function mapper(): PlaylistOutputMapper
    {
        return static::getContainer()->get(PlaylistOutputMapper::class);
    }
}
