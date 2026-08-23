<?php

declare(strict_types=1);

namespace App\Tests\Functional\Playlist;

use App\Entity\Band;
use App\Entity\Concert;
use App\Entity\ProviderSetting;
use App\Entity\Setlist;
use App\Entity\Song;
use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Provider\PlaybackMode;
use App\Service\Provider\ProviderRegistry;
use App\Tests\Functional\Auth\AuthWebTestCase;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared scaffolding for the playlist-14 functional (HTTP) test scope (spec 14 §6/§8, T-FUNC-01…08).
 * Registers/logs in users through the real endpoints (`AuthWebTestCase`'s own pattern), then
 * persists the concert/band/setlist/streaming-account fixtures directly via the container's
 * `EntityManager` — the same "fake the data layer, not the HTTP layer" shape
 * `PlaylistPipelineHappyPathTest` already established for setlist.fm, applied here to fixture setup
 * that has no dedicated HTTP endpoint of its own to drive (streaming account linking would need a
 * full OAuth round trip, which `LinkFlowServiceTest` already covers elsewhere and is not this
 * suite's concern).
 *
 * Only feature-owned tables are truncated (never `provider_settings`, seeded by migration D-102) —
 * the `test-double` provider's row is found-or-created and left `enabled` afterward, exactly as
 * `PlaylistPipelineTestCase` does for the integration suite.
 */
abstract class PlaylistApiTestCase extends AuthWebTestCase
{
    protected function truncatePlaylistTables(): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement('TRUNCATE playlist_tracks, playlists, playlist_generation_jobs, track_resolutions RESTART IDENTITY CASCADE');
        $this->entityManager()->clear();
    }

    protected function ensureTestDoubleProviderSetting(bool $enabled = true): void
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

        static::getContainer()->get('provider.redis')->del(ProviderRegistry::CACHE_KEY);
    }

    protected function userEntity(string $email): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user, \sprintf('User "%s" must exist.', $email));

        return $user;
    }

    /**
     * Persists a `connected` `StreamingAccount` for the user identified by `$email`. Takes an email,
     * not a `User` entity: the `KernelBrowser` reboots the kernel (and rebuilds its container, hence
     * a fresh `EntityManager`) on every HTTP request, so a `User` object fetched before an
     * intervening `$client->request()` call is a detached instance from a since-discarded
     * `EntityManager` by the time this runs — passing an email and refetching here every time avoids
     * that trap entirely, in every helper on this class.
     */
    protected function linkTestDoubleAccount(string $email): StreamingAccount
    {
        $now = new \DateTimeImmutable();
        $account = new StreamingAccount($this->userEntity($email), TestDoubleStreamingProvider::KEY, 'token', null, null, [], 'acct-'.uniqid('', true), null, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    /**
     * @param list<string> $songTitles
     *
     * @return array{concert: Concert, band: Band}
     */
    protected function createConcertWithBand(string $email, array $songTitles = ['Song One', 'Song Two', 'Song Three']): array
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $band = new Band(\sprintf('API Testers %s', uniqid('', true)), \sprintf('api testers %s', uniqid('', true)), $now);
        $em->persist($band);

        $concert = new Concert($this->userEntity($email), $now, 'Europe/Madrid', $now, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);

        $setlist = new Setlist('sl-'.uniqid('', true), $band, new \DateTimeImmutable('2023-07-12'), 'Razzmatazz', 'Barcelona', 'ES', null, $now);
        $em->persist($setlist);
        foreach ($songTitles as $index => $title) {
            $song = new Song($setlist, $index, null, $title, null, null, null, null, false);
            $setlist->addSong($song);
            $em->persist($song);
        }

        $em->flush();

        return ['concert' => $concert, 'band' => $band];
    }

    /** @return array{email: string, accessToken: string} */
    protected function registerLoginAndLink(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $credentials = $this->registerUser($client);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);
        $this->linkTestDoubleAccount($credentials['email']);

        return ['email' => $credentials['email'], 'accessToken' => $login['accessToken']];
    }

    protected function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function testDoubleProvider(): TestDoubleStreamingProvider
    {
        $provider = static::getContainer()->get(TestDoubleStreamingProvider::class);
        self::assertInstanceOf(TestDoubleStreamingProvider::class, $provider);

        return $provider;
    }
}
