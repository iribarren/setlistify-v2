<?php

declare(strict_types=1);

namespace App\Tests\Functional\Playlist;

use App\Entity\Band;
use App\Entity\Concert;
use App\Entity\PlaylistGenerationJob;
use App\Entity\Setlist;
use App\Entity\Song;
use App\Repository\PlaylistGenerationJobRepository;
use App\Repository\StreamingAccountRepository;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\JobMode;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\PlaylistPipeline;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the gap found on `feature/playlist-normal-mode`: `POST /api/playlist-generation-jobs`
 * (`StartGenerationProcessor`) hardcoded `JobMode::Fast` and had no `resumeFromJobId` handling, so
 * neither AC-1.1 ("Choose it yourself" starts a `mode = normal` job) nor AC-4.3 (an `expired`
 * view's primary action resumes into a pre-filled new job) had an entry point at all — every
 * existing Normal-mode test constructed the entity directly. This file drives both through the real
 * HTTP + processor path (docs/specs/2026-08-25-playlist-normal-mode.md, D-190, AC-1.1, AC-4.3).
 */
final class PlaylistNormalModeStartGenerationTest extends PlaylistApiTestCase
{
    /** @return array{email: string, accessToken: string} */
    private function prepare(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $this->truncatePlaylistTables();
        $this->ensureTestDoubleProviderSetting(enabled: true);

        return $this->registerLoginAndLink($client);
    }

    /**
     * A second cached setlist for `$band`, so the band offers a genuine choice (AC-1.3/AC-1.7).
     *
     * @param list<string> $titles
     */
    private function addSecondSetlist(Band $band, \DateTimeImmutable $eventDate, array $titles): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $setlist = new Setlist('sl-'.uniqid('', true), $band, $eventDate, 'Wembley Arena', 'London', 'GB', null, $now);
        $em->persist($setlist);
        foreach ($titles as $index => $title) {
            $song = new Song($setlist, $index, null, $title, null, null, null, null, false);
            $setlist->addSong($song);
            $em->persist($song);
        }
        $em->flush();
    }

    private function postStartGeneration(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $accessToken, int $concertId, ?string $mode = null, ?int $resumeFromJobId = null): void
    {
        $payload = ['concertId' => $concertId, 'provider' => TestDoubleStreamingProvider::KEY];
        if (null !== $mode) {
            $payload['mode'] = $mode;
        }
        if (null !== $resumeFromJobId) {
            $payload['resumeFromJobId'] = $resumeFromJobId;
        }

        $client->request(
            'POST',
            '/api/playlist-generation-jobs',
            server: array_merge(self::authHeaders($accessToken), ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json']),
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, string> */
    private static function authHeaders(string $accessToken): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken];
    }

    public function testModeNormalStartsANormalModeJobThatReachesAwaitingSetlistChoiceForAMultiSetlistBand(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $user = $this->prepare($client);
        $fixture = $this->createConcertWithBand($user['email']);
        $this->addSecondSetlist($fixture['band'], new \DateTimeImmutable('2024-06-01'), ['Other Song One', 'Other Song Two']);

        $this->postStartGeneration($client, $user['accessToken'], (int) $fixture['concert']->getId(), mode: 'normal');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $client->getResponse()->getContent());
        $created = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame('normal', $created['mode']);
        self::assertSame('queued', $created['state']);

        $jobRepository = static::getContainer()->get(PlaylistGenerationJobRepository::class);
        $job = $jobRepository->find($created['id']);
        self::assertInstanceOf(PlaylistGenerationJob::class, $job);
        self::assertSame(JobMode::Normal, $job->getMode());

        static::getContainer()->get(JobStateMachine::class)->startResolvingSetlist($job);
        static::getContainer()->get(PlaylistPipeline::class)->run($job);

        $this->entityManager()->clear();
        $job = $jobRepository->find($created['id']);
        self::assertInstanceOf(PlaylistGenerationJob::class, $job);
        self::assertSame(JobState::AwaitingSetlistChoice, $job->getState(), 'A multi-setlist band must suspend for a setlist choice in Normal mode (T-04).');
    }

    public function testResumeFromJobIdAgainstANonExpiredJobIs422(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $user = $this->prepare($client);
        $fixture = $this->createConcertWithBand($user['email']);
        $otherFixture = $this->createConcertWithBand($user['email']);

        $account = static::getContainer()->get(StreamingAccountRepository::class)->findOneByUserAndProvider($this->userEntity($user['email'])->getId() ?? 0, TestDoubleStreamingProvider::KEY);
        self::assertInstanceOf(\App\Entity\StreamingAccount::class, $account);
        $stillQueued = new PlaylistGenerationJob($this->userEntity($user['email']), $otherFixture['concert'], TestDoubleStreamingProvider::KEY, $account, JobMode::Normal, str_repeat('q', 64), 1, new \DateTimeImmutable());
        $this->entityManager()->persist($stillQueued);
        $this->entityManager()->flush();
        $stillQueuedId = $stillQueued->getId();

        $this->postStartGeneration($client, $user['accessToken'], (int) $fixture['concert']->getId(), resumeFromJobId: $stillQueuedId);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $client->getResponse()->getContent());
    }

    public function testResumeFromJobIdAgainstAnotherOwnersJobIs404ByteIdenticalToUnknown(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->prepare($client);
        $ownerFixture = $this->createConcertWithBand($owner['email']);
        $expiredJob = $this->makeExpiredJob($owner['email'], $ownerFixture['concert']);
        $expiredJobId = $expiredJob->getId();

        $intruder = $this->registerUser($client);
        $intruderLogin = $this->loginUser($client, $intruder['email'], $intruder['password']);
        $this->linkTestDoubleAccount($intruder['email']);
        $intruderFixture = $this->createConcertWithBand($intruder['email']);

        $this->postStartGeneration($client, $intruderLogin['accessToken'], (int) $intruderFixture['concert']->getId(), resumeFromJobId: $expiredJobId);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crossOwnerBody = (string) $client->getResponse()->getContent();

        $this->postStartGeneration($client, $intruderLogin['accessToken'], (int) $intruderFixture['concert']->getId(), resumeFromJobId: 999999999);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame($crossOwnerBody, (string) $client->getResponse()->getContent(), 'D-157: a cross-owner id and an unknown id must be byte-identical 404s.');
    }

    public function testResumeFromJobIdAgainstAGenuinelyExpiredJobPreFillsTheNewJob(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->prepare($client);
        $fixture = $this->createConcertWithBand($owner['email']);
        $this->addSecondSetlist($fixture['band'], new \DateTimeImmutable('2024-06-01'), ['Other Song One', 'Other Song Two']);

        $expiredJob = $this->makeExpiredJobWithSetlistChoice($owner['email'], $fixture['concert'], $fixture['band']);
        $expiredJobId = $expiredJob->getId();
        $chosenSetlistfmId = self::firstSetlistChoiceId($expiredJob);

        $this->postStartGeneration($client, $owner['accessToken'], (int) $fixture['concert']->getId(), mode: 'normal', resumeFromJobId: $expiredJobId);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $client->getResponse()->getContent());
        $created = self::decodeJsonObject((string) $client->getResponse()->getContent());

        $jobRepository = static::getContainer()->get(PlaylistGenerationJobRepository::class);
        $newJob = $jobRepository->find($created['id']);
        self::assertInstanceOf(PlaylistGenerationJob::class, $newJob);
        $resumedFrom = $newJob->getResumedFromJob();
        self::assertInstanceOf(PlaylistGenerationJob::class, $resumedFrom);
        self::assertSame($expiredJobId, $resumedFrom->getId());
        self::assertSame([$chosenSetlistfmId], [self::firstSetlistChoiceId($newJob)], 'AC-4.3: userChoices carried over from the expired job.');

        // The deeper pre-fill (AC-4.3): SetlistSelectionStage recommends the same setlist again.
        static::getContainer()->get(JobStateMachine::class)->startResolvingSetlist($newJob);
        static::getContainer()->get(PlaylistPipeline::class)->run($newJob);

        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d/candidate-setlists', $newJob->getId() ?? 0), server: self::authHeaders($owner['accessToken']));
        self::assertResponseIsSuccessful();
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertIsArray($data['bands']);
        $bandEntry = $data['bands'][0];
        self::assertIsArray($bandEntry);
        self::assertSame($chosenSetlistfmId, $bandEntry['recommendedSetlistfmId']);
        self::assertSame('resumed_from_previous_choice', $bandEntry['recommendedReason']);
    }

    private static function firstSetlistChoiceId(PlaylistGenerationJob $job): string
    {
        $userChoices = $job->getUserChoices();
        self::assertIsArray($userChoices);
        $setlistChoices = $userChoices['setlistChoices'] ?? null;
        self::assertIsArray($setlistChoices);
        $first = $setlistChoices[0];
        self::assertIsArray($first);
        $setlistfmId = $first['setlistfmId'];
        self::assertIsString($setlistfmId);

        return $setlistfmId;
    }

    /** Suspends, then expires, a fresh job — no setlist choice recorded (a plain expiry). */
    private function makeExpiredJob(string $email, Concert $concert): PlaylistGenerationJob
    {
        $now = new \DateTimeImmutable();
        $user = $this->userEntity($email);
        $account = static::getContainer()->get(StreamingAccountRepository::class)->findOneByUserAndProvider($user->getId() ?? 0, TestDoubleStreamingProvider::KEY);
        self::assertInstanceOf(\App\Entity\StreamingAccount::class, $account);
        $job = new PlaylistGenerationJob($user, $concert, TestDoubleStreamingProvider::KEY, $account, JobMode::Normal, str_repeat('e', 64), 1, $now);
        $this->entityManager()->persist($job);
        $this->entityManager()->flush();

        $stateMachine = static::getContainer()->get(JobStateMachine::class);
        $stateMachine->startResolvingSetlist($job);
        $stateMachine->suspendForSetlistChoice($job, $now->modify('+7 days'));
        $stateMachine->expire($job);
        $this->entityManager()->flush();

        return $job;
    }

    /** Same as {@see makeExpiredJob()}, but with a `userChoices['setlistChoices']` entry already recorded, as `SetlistChoiceApplier` would have written before the job later expired at the version step. */
    private function makeExpiredJobWithSetlistChoice(string $email, Concert $concert, Band $band): PlaylistGenerationJob
    {
        $job = $this->makeExpiredJob($email, $concert);

        // The chosen setlistfmId must be one that is actually cached, so the pre-fill re-run finds
        // it among the new job's candidates — reuse the first cached one for this band.
        /** @var list<Setlist> $cached */
        $cached = static::getContainer()->get(\App\Repository\SetlistRepository::class)->createBandSetlistsQueryBuilder($band)->getQuery()->getResult();
        self::assertNotSame([], $cached);
        $job->setUserChoices(['setlistChoices' => [['bandId' => $band->getId(), 'setlistfmId' => $cached[0]->getSetlistfmId()]]]);
        $this->entityManager()->flush();

        return $job;
    }
}
