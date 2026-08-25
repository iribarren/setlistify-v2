<?php

declare(strict_types=1);

namespace App\Tests\Functional\Playlist;

use App\Entity\Concert;
use App\Entity\PlaylistGenerationJob;
use App\Repository\StreamingAccountRepository;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\JobMode;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-157/D-190 (docs/specs/2026-08-25-playlist-normal-mode.md): the four new operations are filtered
 * by the existing `PlaylistGenerationJobOwnerExtension` before any voter runs — another owner's job
 * id must be a 404 byte-identical to a missing id, exactly like every other job endpoint (AC-8 in
 * `PlaylistGenerationApiTest`).
 */
final class PlaylistNormalModeOwnershipTest extends PlaylistApiTestCase
{
    /** @return array{email: string, accessToken: string} */
    private function prepareOwner(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $this->truncatePlaylistTables();
        $this->ensureTestDoubleProviderSetting(enabled: true);

        return $this->registerLoginAndLink($client);
    }

    private function persistNormalJob(string $email, Concert $concert): PlaylistGenerationJob
    {
        $now = new \DateTimeImmutable();
        $user = $this->userEntity($email);
        // `registerLoginAndLink()` already linked one test-double account per user
        // (`uniq_streaming_accounts_user_provider`) — reuse it rather than colliding with a second.
        $account = static::getContainer()->get(StreamingAccountRepository::class)->findOneByUserAndProvider($user->getId() ?? 0, TestDoubleStreamingProvider::KEY);
        self::assertInstanceOf(\App\Entity\StreamingAccount::class, $account);
        $job = new PlaylistGenerationJob($user, $concert, TestDoubleStreamingProvider::KEY, $account, JobMode::Normal, str_repeat('n', 64), 1, $now);
        $this->entityManager()->persist($job);
        $this->entityManager()->flush();

        return $job;
    }

    private function suspendForSetlistChoice(PlaylistGenerationJob $job): void
    {
        $stateMachine = static::getContainer()->get(JobStateMachine::class);
        $stateMachine->startResolvingSetlist($job);
        $stateMachine->suspendForSetlistChoice($job, new \DateTimeImmutable('+7 days'));
        $job->setCandidateSetlists([]);
        $this->entityManager()->flush();
    }

    private function suspendForVersionChoice(PlaylistGenerationJob $job): void
    {
        $stateMachine = static::getContainer()->get(JobStateMachine::class);
        $stateMachine->startResolvingSetlist($job);
        $stateMachine->enterMatching($job);
        $stateMachine->suspendForVersionChoice($job, new \DateTimeImmutable('+72 hours'));
        $job->setPendingChoices(['songsTotal' => 0, 'autoResolvedCount' => 0, 'choicesRequiredCount' => 0, 'autoResolved' => [], 'decisions' => []]);
        $this->entityManager()->flush();
    }

    /** @return array<string, string> */
    private static function authHeaders(string $accessToken): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken];
    }

    public function testCandidateSetlistsCrossOwnerIs404ByteIdenticalToMissing(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->prepareOwner($client);
        $fixture = $this->createConcertWithBand($owner['email']);
        $job = $this->persistNormalJob($owner['email'], $fixture['concert']);
        $this->suspendForSetlistChoice($job);
        $jobId = $job->getId();

        $intruder = $this->registerUser($client);
        $intruderLogin = $this->loginUser($client, $intruder['email'], $intruder['password']);

        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d/candidate-setlists', $jobId), server: self::authHeaders($intruderLogin['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crossOwnerBody = (string) $client->getResponse()->getContent();

        $client->request('GET', '/api/playlist-generation-jobs/999999999/candidate-setlists', server: self::authHeaders($intruderLogin['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame((string) $client->getResponse()->getContent(), $crossOwnerBody);
    }

    public function testSetlistChoiceCrossOwnerIs404(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->prepareOwner($client);
        $fixture = $this->createConcertWithBand($owner['email']);
        $job = $this->persistNormalJob($owner['email'], $fixture['concert']);
        $this->suspendForSetlistChoice($job);
        $jobId = $job->getId();

        $intruder = $this->registerUser($client);
        $intruderLogin = $this->loginUser($client, $intruder['email'], $intruder['password']);

        $client->request(
            'POST',
            \sprintf('/api/playlist-generation-jobs/%d/setlist-choice', $jobId),
            server: array_merge(self::authHeaders($intruderLogin['accessToken']), ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json']),
            content: json_encode(['choices' => [['bandId' => $fixture['band']->getId(), 'setlistfmId' => 'whatever']]], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testPendingChoicesCrossOwnerIs404ByteIdenticalToMissing(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->prepareOwner($client);
        $fixture = $this->createConcertWithBand($owner['email']);
        $job = $this->persistNormalJob($owner['email'], $fixture['concert']);
        $this->suspendForVersionChoice($job);
        $jobId = $job->getId();

        $intruder = $this->registerUser($client);
        $intruderLogin = $this->loginUser($client, $intruder['email'], $intruder['password']);

        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d/pending-choices', $jobId), server: self::authHeaders($intruderLogin['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crossOwnerBody = (string) $client->getResponse()->getContent();

        $client->request('GET', '/api/playlist-generation-jobs/999999999/pending-choices', server: self::authHeaders($intruderLogin['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame((string) $client->getResponse()->getContent(), $crossOwnerBody);
    }

    public function testVersionChoicesCrossOwnerIs404(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->prepareOwner($client);
        $fixture = $this->createConcertWithBand($owner['email']);
        $job = $this->persistNormalJob($owner['email'], $fixture['concert']);
        $this->suspendForVersionChoice($job);
        $jobId = $job->getId();

        $intruder = $this->registerUser($client);
        $intruderLogin = $this->loginUser($client, $intruder['email'], $intruder['password']);

        $client->request(
            'POST',
            \sprintf('/api/playlist-generation-jobs/%d/version-choices', $jobId),
            server: array_merge(self::authHeaders($intruderLogin['accessToken']), ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json']),
            content: json_encode(['choices' => []], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCandidateSetlistsWrongStateIs422(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->prepareOwner($client);
        $fixture = $this->createConcertWithBand($owner['email']);
        $job = $this->persistNormalJob($owner['email'], $fixture['concert']); // still `queued`
        $jobId = $job->getId();

        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d/candidate-setlists', $jobId), server: self::authHeaders($owner['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testPendingChoicesHappyPathReturns200WithShapedBody(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->prepareOwner($client);
        $fixture = $this->createConcertWithBand($owner['email']);
        $job = $this->persistNormalJob($owner['email'], $fixture['concert']);
        $this->suspendForVersionChoice($job);
        $jobId = $job->getId();

        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d/pending-choices', $jobId), server: self::authHeaders($owner['accessToken']));
        self::assertResponseIsSuccessful();
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame($jobId, $data['jobId']);
        self::assertArrayHasKey('autoResolved', $data);
        self::assertArrayHasKey('decisions', $data);
        self::assertStringNotContainsString('"confidence"', (string) $client->getResponse()->getContent());
    }
}
