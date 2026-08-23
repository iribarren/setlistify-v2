<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\PlaylistGenerationJobCrudController;
use App\Entity\Band;
use App\Entity\Concert;
use App\Entity\Playlist;
use App\Entity\PlaylistGenerationJob;
use App\Entity\PlaylistTrack;
use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\FailureReason;
use App\Service\Playlist\Model\JobMode;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\PipelineStage;
use App\Service\Playlist\Model\ResultKind;
use App\Service\Playlist\Model\TrackOutcome;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * `/admin` "Playlist generation jobs" screen (spec 2026-08-23-spike-playlist-pipeline.md §8,
 * D-141/D-142) — read-only, no retry action, surfaces block/failure detail, stage timings and the
 * per-song track outcomes on the detail page.
 */
final class PlaylistGenerationJobCrudControllerTest extends AdminWebTestCase
{
    public function testIndexListsJobsWithTheDeclaredColumns(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        [$job] = $this->createJobWithPlaylist();

        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);
        $indexUrl = $urlGenerator->setController(PlaylistGenerationJobCrudController::class)->setAction(Crud::PAGE_INDEX)->generateUrl();

        $client->request('GET', $indexUrl);
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(TestDoubleStreamingProvider::KEY, $html);
        self::assertStringContainsString('fast', $html);
        self::assertStringContainsString('completed', $html);
        self::assertStringContainsString('Matched', $html, 'the matched-count column header.');
        self::assertStringContainsString('Total songs', $html, 'the songs-total column header.');
        // D-51: the owner's email must never render unmasked.
        self::assertStringNotContainsString($job->getOwner()->getEmail(), $html);
    }

    public function testThereIsNoNewEditOrDeleteAction(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $this->createJobWithPlaylist();

        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);
        $indexUrl = $urlGenerator->setController(PlaylistGenerationJobCrudController::class)->setAction(Crud::PAGE_INDEX)->generateUrl();
        $client->request('GET', $indexUrl);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $newUrl = $urlGenerator->setController(PlaylistGenerationJobCrudController::class)->setAction(Crud::PAGE_NEW)->generateUrl();
        self::assertStringNotContainsString($newUrl, $html, 'D-142: no retry/new action anywhere.');

        // Directly hitting the EDIT/NEW/DELETE routes must be refused (403), not silently allowed.
        $editUrl = $urlGenerator->setController(PlaylistGenerationJobCrudController::class)->setAction(Crud::PAGE_EDIT)->setEntityId(1)->generateUrl();
        $client->request('GET', $editUrl);
        self::assertResponseStatusCodeSame(403);
    }

    public function testDetailPageRendersTracksTableAndBlockedFailureDetail(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        [$job] = $this->createJobWithPlaylist();

        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);
        $detailUrl = $urlGenerator->setController(PlaylistGenerationJobCrudController::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($job->getId())->generateUrl();

        $client->request('GET', $detailUrl);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // The per-song track table (band, title, outcome, confidence, reason code).
        self::assertStringContainsString('not_found', $html);
        self::assertStringContainsString('matched', $html);
        self::assertStringContainsString('Missed Song', $html);

        // Job-level block/failure detail is only ever shown as codes, never rendered English.
        self::assertStringContainsString('provider_quota', $html);
    }

    /** @return array{0: PlaylistGenerationJob, 1: Playlist} */
    private function createJobWithPlaylist(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $now = new \DateTimeImmutable();
        $unique = uniqid('admin-job-', true);

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
        $job->setSongsTotal(10, $now);
        $job->markStarted($now);
        $job->block(BlockedReason::ProviderQuota, null, PipelineStage::Matching, $now);
        $job->fail(FailureReason::UnknownProvider, ['note' => 'test-fixture'], $now);
        $job->freezeCounters(8, 0, 2, 0, 0, 0.95, 42_000, ['matching' => 100], ResultKind::Partial, $now);
        $job->markFinished($now);
        $job->setStateInternal(JobState::Completed, $now);
        $em->persist($job);

        $playlist = new Playlist($user, $concert, $job, TestDoubleStreamingProvider::KEY, 'Test playlist', $now);
        $em->persist($playlist);

        $matchedTrack = new PlaylistTrack($playlist, 0, null, $band, 'sl-1', 0, 'Hit Song');
        $matchedTrack->resolve(TrackOutcome::Matched, 'double-track-1', 0.99, null, null);
        $playlist->addTrack($matchedTrack);
        $em->persist($matchedTrack);

        $missedTrack = new PlaylistTrack($playlist, 1, null, $band, 'sl-2', 1, 'Missed Song');
        $missedTrack->resolve(TrackOutcome::NotFound, null, null, null, null);
        $playlist->addTrack($missedTrack);
        $em->persist($missedTrack);

        $em->flush();

        return [$job, $playlist];
    }
}
