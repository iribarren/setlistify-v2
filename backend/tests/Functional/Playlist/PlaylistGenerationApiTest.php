<?php

declare(strict_types=1);

namespace App\Tests\Functional\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\JobMode;
use App\Service\Playlist\Model\JobState;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-FUNC-01, T-FUNC-02, T-FUNC-04 (spec 14 §6/§8): the HTTP surface's own contract — status codes,
 * ownership (404 never 403, `ConcertOwnerExtension`-shaped, untouched), the polling contract's
 * `ETag`/304 and per-state `Retry-After`, and a `blocked` job answering 200 rather than an error
 * status. Generation itself (matching, creation, insertion) is `PlaylistPipelineTestCase`'s scope —
 * these tests never run the pipeline; Messenger is `in-memory://` in `test` (spec 14 §9), so a
 * `POST` genuinely makes zero provider calls on the request thread, and every other state this file
 * needs is written directly through `JobStateMachine`, the same class `BuildPlaylistHandler` itself
 * would call.
 */
final class PlaylistGenerationApiTest extends PlaylistApiTestCase
{
    protected function tearDown(): void
    {
        $this->ensureTestDoubleProviderSetting(enabled: true);
        parent::tearDown();
    }

    /**
     * `createAuthClient()` must be the FIRST container-touching call in every test (its own
     * docblock: `getContainer()` before `createClient()` makes `createClient()` throw) — so
     * truncation/provider-setup, which needs the container, happens here, right after the client is
     * created, rather than in `setUp()`.
     */
    /** @param array<string, mixed> $options */
    private function prepare(array $options = []): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = $this->createAuthClient($options);
        $this->truncatePlaylistTables();
        $this->ensureTestDoubleProviderSetting(enabled: true);

        return $client;
    }

    public function testStartingAGenerationReturns201WithAQueuedJobAndMakesNoProviderCallsOnTheRequestThread(): void
    {
        $client = $this->prepare();
        $user = $this->registerLoginAndLink($client);
        $fixture = $this->createConcertWithBand($user['email']);

        $this->postStartGeneration($client, $user['accessToken'], (int) $fixture['concert']->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame('queued', $data['state']);
        self::assertSame(0, $data['songsProcessed']);

        self::assertSame(0, $this->testDoubleProvider()->getSearchTrackCallCount(), 'AC-1.1: zero provider calls on the request thread.');
    }

    public function testASecondPostForTheSameLiveGenerationReturns200WithTheExistingJobNeverA409(): void
    {
        $client = $this->prepare();
        $user = $this->registerLoginAndLink($client);
        $fixture = $this->createConcertWithBand($user['email']);
        $concertId = (int) $fixture['concert']->getId();

        $this->postStartGeneration($client, $user['accessToken'], $concertId);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = self::decodeJsonObject((string) $client->getResponse()->getContent());

        $this->postStartGeneration($client, $user['accessToken'], $concertId);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $second = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame($first['id'], $second['id'], 'D-129: never a second job for the same live (concert, provider).');
    }

    public function testGetOnAnotherOwnersJobReturns404NeverA403(): void
    {
        $client = $this->prepare(['debug' => false]);
        $owner = $this->registerLoginAndLink($client);
        $fixture = $this->createConcertWithBand($owner['email']);
        $job = $this->persistJob($owner['email'], $fixture['concert']);
        $jobId = $job->getId();

        $intruderCredentials = $this->registerUser($client);
        $intruderLogin = $this->loginUser($client, $intruderCredentials['email'], $intruderCredentials['password']);

        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d', $jobId), server: self::authHeaders($intruderLogin['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crossOwnerBody = (string) $client->getResponse()->getContent();

        $client->request('GET', '/api/playlist-generation-jobs/999999999', server: self::authHeaders($intruderLogin['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $missingBody = (string) $client->getResponse()->getContent();

        self::assertSame($missingBody, $crossOwnerBody, 'AC-8: existence must not leak — a cross-owner id and a missing id are byte-identical 404s.');
    }

    public function testPlaylistGetOnAnotherOwnersPlaylistReturns404NeverA403(): void
    {
        $client = $this->prepare(['debug' => false]);
        $owner = $this->registerLoginAndLink($client);
        $fixture = $this->createConcertWithBand($owner['email']);
        $job = $this->persistJob($owner['email'], $fixture['concert']);
        $playlist = $this->persistPlaylist($owner['email'], $fixture['concert'], $job);
        $playlistId = $playlist->getId();

        $intruderCredentials = $this->registerUser($client);
        $intruderLogin = $this->loginUser($client, $intruderCredentials['email'], $intruderCredentials['password']);

        $client->request('GET', \sprintf('/api/playlists/%d', $playlistId), server: self::authHeaders($intruderLogin['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('GET', '/api/playlists/999999999', server: self::authHeaders($intruderLogin['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnonymousRequestsToJobEndpointsReturn401(): void
    {
        $client = $this->createAuthClient();

        $client->request('GET', '/api/playlist-generation-jobs');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('GET', '/api/playlist-generation-jobs/1');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request(
            'POST',
            '/api/playlist-generation-jobs',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['concertId' => 1], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRetryAfterHeaderMatchesTheJobsStateAndIsAbsentOnceTerminalOrBlocked(): void
    {
        $client = $this->prepare();

        // D-144's anti-starvation index allows only ONE live (queued/resolving_setlist/matching/
        // building) job per user across all concerts — so each state below needs its own user,
        // not just its own concert, or creating the second job would itself collide.
        $queuedUser = $this->registerLoginAndLink($client);
        $queuedFixture = $this->createConcertWithBand($queuedUser['email']);
        $queuedJobId = $this->persistJob($queuedUser['email'], $queuedFixture['concert'])->getId();
        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d', $queuedJobId), server: self::authHeaders($queuedUser['accessToken']));
        self::assertSame('3', $client->getResponse()->headers->get('Retry-After'), 'queued -> Retry-After: 3.');

        $matchingUser = $this->registerLoginAndLink($client);
        $matchingFixture = $this->createConcertWithBand($matchingUser['email']);
        $matchingJob = $this->persistJob($matchingUser['email'], $matchingFixture['concert']);
        $this->moveJobToState($matchingJob, JobState::Matching);
        $matchingJobId = $matchingJob->getId();
        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d', $matchingJobId), server: self::authHeaders($matchingUser['accessToken']));
        self::assertSame('1', $client->getResponse()->headers->get('Retry-After'), 'matching -> Retry-After: 1.');

        $completedUser = $this->registerLoginAndLink($client);
        $completedFixture = $this->createConcertWithBand($completedUser['email']);
        $completedJob = $this->persistJob($completedUser['email'], $completedFixture['concert']);
        $this->moveJobToState($completedJob, JobState::Completed);
        $completedJobId = $completedJob->getId();
        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d', $completedJobId), server: self::authHeaders($completedUser['accessToken']));
        self::assertNull($client->getResponse()->headers->get('Retry-After'), 'A terminal state carries no Retry-After.');

        $blockedUser = $this->registerLoginAndLink($client);
        $blockedFixture = $this->createConcertWithBand($blockedUser['email']);
        $blockedJob = $this->persistJob($blockedUser['email'], $blockedFixture['concert']);
        $this->moveJobToState($blockedJob, JobState::Blocked);
        $blockedJobId = $blockedJob->getId();
        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d', $blockedJobId), server: self::authHeaders($blockedUser['accessToken']));
        self::assertNull($client->getResponse()->headers->get('Retry-After'), 'A blocked job carries no Retry-After either (D-150).');
    }

    public function testEtagIsPresentAndAMatchingIfNoneMatchReturns304WithNoBody(): void
    {
        $client = $this->prepare();
        $user = $this->registerLoginAndLink($client);
        $fixture = $this->createConcertWithBand($user['email']);
        $jobId = $this->persistJob($user['email'], $fixture['concert'])->getId();

        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d', $jobId), server: self::authHeaders($user['accessToken']));
        self::assertResponseIsSuccessful();
        $etag = $client->getResponse()->headers->get('ETag');
        self::assertNotNull($etag);

        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d', $jobId), server: array_merge(self::authHeaders($user['accessToken']), ['HTTP_IF_NONE_MATCH' => $etag]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
        self::assertSame('', (string) $client->getResponse()->getContent());
    }

    public function testABlockedJobIsReturnedAsHttp200NeverAnErrorStatus(): void
    {
        $client = $this->prepare();
        $user = $this->registerLoginAndLink($client);
        $fixture = $this->createConcertWithBand($user['email']);
        $job = $this->persistJob($user['email'], $fixture['concert']);
        $this->moveJobToState($job, JobState::Blocked, BlockedReason::ProviderQuota);
        $jobId = $job->getId();

        $client->request('GET', \sprintf('/api/playlist-generation-jobs/%d', $jobId), server: self::authHeaders($user['accessToken']));

        self::assertResponseStatusCodeSame(Response::HTTP_OK, 'R-2: blocked must never be rendered as an HTTP error.');
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame('blocked', $data['state']);
        self::assertSame('provider_quota', $data['blockedReason']);
    }

    private function postStartGeneration(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $accessToken, int $concertId): void
    {
        $client->request(
            'POST',
            '/api/playlist-generation-jobs',
            server: array_merge(self::authHeaders($accessToken), ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json']),
            content: json_encode(['concertId' => $concertId, 'provider' => TestDoubleStreamingProvider::KEY], \JSON_THROW_ON_ERROR),
        );
    }

    /** `$concert` must have been fetched/created in THIS same container lifetime (no HTTP request since) — see `PlaylistApiTestCase`'s docblock on why entities can't cross a request boundary. */
    private function persistJob(string $email, \App\Entity\Concert $concert): PlaylistGenerationJob
    {
        $now = new \DateTimeImmutable();
        $user = $this->userEntity($email);
        $account = $this->linkableAccountFor($email);
        $job = new PlaylistGenerationJob($user, $concert, TestDoubleStreamingProvider::KEY, $account, JobMode::Fast, str_repeat('f', 64), 1, $now);
        $this->entityManager()->persist($job);
        $this->entityManager()->flush();

        return $job;
    }

    /** `$concert`/`$job` must have been fetched/created in THIS same container lifetime (no HTTP request since). */
    private function persistPlaylist(string $email, \App\Entity\Concert $concert, PlaylistGenerationJob $job): \App\Entity\Playlist
    {
        $now = new \DateTimeImmutable();
        $playlist = new \App\Entity\Playlist($this->userEntity($email), $concert, $job, TestDoubleStreamingProvider::KEY, 'Test Playlist', $now);
        $this->entityManager()->persist($playlist);
        $this->entityManager()->flush();

        return $playlist;
    }

    /** Reuses the user's existing `test-double` account, linking one if this is the first job for them. */
    private function linkableAccountFor(string $email): \App\Entity\StreamingAccount
    {
        $user = $this->userEntity($email);
        $repository = static::getContainer()->get(\App\Repository\StreamingAccountRepository::class);
        $existing = $repository->findOneByUserAndProvider($user->getId() ?? 0, TestDoubleStreamingProvider::KEY);

        return $existing ?? $this->linkTestDoubleAccount($email);
    }

    private function moveJobToState(PlaylistGenerationJob $job, JobState $target, ?BlockedReason $blockedReason = null): void
    {
        $stateMachine = static::getContainer()->get(JobStateMachine::class);
        $stateMachine->startResolvingSetlist($job);

        match ($target) {
            JobState::ResolvingSetlist => null,
            JobState::Matching => $stateMachine->enterMatching($job),
            JobState::Building => (function () use ($stateMachine, $job): void {
                $stateMachine->enterMatching($job);
                $stateMachine->enterBuilding($job);
            })(),
            JobState::Completed => $stateMachine->complete($job),
            JobState::Blocked => $stateMachine->block($job, $blockedReason ?? BlockedReason::ProviderQuota, null, null),
            default => throw new \LogicException(\sprintf('Unsupported target state "%s" in this test helper.', $target->value)),
        };
    }

    /** @return array<string, string> */
    private static function authHeaders(string $accessToken): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken];
    }
}
