<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\PlaylistCrudController;
use App\Entity\Band;
use App\Entity\Concert;
use App\Entity\Playlist;
use App\Entity\PlaylistGenerationJob;
use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Service\Playlist\Model\JobMode;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * `/admin` "Playlists" screen — read-only list of generated playlists, with the report summary
 * (spec 2026-08-23-spike-playlist-pipeline.md §8, D-142).
 */
final class PlaylistCrudControllerTest extends AdminWebTestCase
{
    public function testIndexListsPlaylistsWithTheReportSummary(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        [$playlist] = $this->createPlaylist();

        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);
        $indexUrl = $urlGenerator->setController(PlaylistCrudController::class)->setAction(Crud::PAGE_INDEX)->generateUrl();

        $client->request('GET', $indexUrl);
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Test playlist', $html);
        self::assertStringContainsString('NO_SETLIST_FOR_BAND', $html, 'the report summary code must render.');
        self::assertStringNotContainsString($playlist->getOwner()->getEmail(), $html, 'D-51: owner email must never render unmasked.');
    }

    public function testThereIsNoNewEditOrDeleteAction(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $this->createPlaylist();

        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);
        $indexUrl = $urlGenerator->setController(PlaylistCrudController::class)->setAction(Crud::PAGE_INDEX)->generateUrl();
        $client->request('GET', $indexUrl);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $newUrl = $urlGenerator->setController(PlaylistCrudController::class)->setAction(Crud::PAGE_NEW)->generateUrl();
        self::assertStringNotContainsString($newUrl, $html);

        $editUrl = $urlGenerator->setController(PlaylistCrudController::class)->setAction(Crud::PAGE_EDIT)->setEntityId(1)->generateUrl();
        $client->request('GET', $editUrl);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array{0: Playlist} */
    private function createPlaylist(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $now = new \DateTimeImmutable();
        $unique = uniqid('admin-playlist-', true);

        $user = new User(\sprintf('fan-%s@example.com', $unique), 'hash');
        $em->persist($user);

        $band = new Band(\sprintf('The Testers %s', $unique), \sprintf('the testers %s', $unique), $now);
        $em->persist($band);

        $concert = new Concert($user, $now, 'Europe/Madrid', $now, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);

        $account = new StreamingAccount($user, TestDoubleStreamingProvider::KEY, 'token', null, null, [], 'acct-'.$unique, null, $now);
        $em->persist($account);

        $job = new PlaylistGenerationJob($user, $concert, TestDoubleStreamingProvider::KEY, $account, JobMode::Fast, bin2hex(random_bytes(32)), 1, $now);
        $em->persist($job);

        $playlist = new Playlist($user, $concert, $job, TestDoubleStreamingProvider::KEY, 'Test playlist', $now);
        $playlist->addReportEntry('NO_SETLIST_FOR_BAND', ['band' => $band->getName()], $now);
        $em->persist($playlist);

        $em->flush();

        return [$playlist];
    }
}
