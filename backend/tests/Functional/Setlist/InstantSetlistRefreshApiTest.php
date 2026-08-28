<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setlist;

use App\Entity\Band;
use App\Entity\Concert;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Concert\BandResolver;
use App\Service\Setlist\SetlistRefreshCoordinator;
use App\Tests\Functional\Auth\AuthWebTestCase;
use App\Tests\Support\Setlist\CountingSetlistFmHttpClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Instant setlist refresh's HTTP surface (docs/specs/2026-08-27-instant-setlist-refresh.md, US-1,
 * US-3, US-4, US-6, US-11). Zero outbound setlist.fm calls happen on the request thread (AC-3.1,
 * AC-11.5) — asserted with `CountingSetlistFmHttpClient`, the same spy AC-5.6 of spec 09 already
 * established.
 */
final class InstantSetlistRefreshApiTest extends AuthWebTestCase
{
    protected function tearDown(): void
    {
        $this->countingClient()->reset();
        parent::tearDown();
    }

    /**
     * Clears every setlist.fm-namespaced Redis key first — the cooldown/daily-cap counters this
     * suite exercises are real and shared across the whole `test` environment's Redis namespace
     * (same reasoning as `AuthWebTestCase::createAuthClient()`'s rate-limiter clear), so leftover
     * state from one test method must not leak into the next.
     */
    private function resetSetlistfmRedis(): void
    {
        $redis = static::getContainer()->get('setlistfm.redis');
        $keys = $redis->keys('setlistfm:*');
        if ([] !== $keys) {
            $redis->del($keys);
        }
    }

    public function testAnUnentitledUsersTriggerIsForbiddenAndMakesZeroOutboundCalls(): void
    {
        $client = $this->createAuthClient();
        $this->countingClient()->reset();
        $this->resetSetlistfmRedis();
        $credentials = $this->registerUser($client);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);
        $fixture = $this->createConcertWithBand($credentials['email']);

        $client->request('POST', \sprintf('/api/bands/%d/setlist-refresh', $fixture['band']->getId()), server: self::authHeaders($login['accessToken']));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->countingClient()->getRequestCount(), 'AC-1.2/AC-11.5: an unentitled trigger must attempt zero outbound calls.');
    }

    public function testUnknownBandReturns404(): void
    {
        $client = $this->createAuthClient();
        $this->resetSetlistfmRedis();
        $credentials = $this->registerUser($client);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);
        $this->grantInstantRefresh($credentials['email']);

        $client->request('POST', '/api/bands/999999999/setlist-refresh', server: self::authHeaders($login['accessToken']));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** AC-1.3/D-266: a band that exists but is on none of the caller's concerts is 422, not 403/404. */
    public function testBandNotOnCallersConcertsReturns422WithTypedReason(): void
    {
        $client = $this->createAuthClient();
        $this->resetSetlistfmRedis();
        $owner = $this->registerUser($client);
        $ownerLogin = $this->loginUser($client, $owner['email'], $owner['password']);
        $ownerFixture = $this->createConcertWithBand($owner['email']);

        $intruder = $this->registerUser($client);
        $intruderLogin = $this->loginUser($client, $intruder['email'], $intruder['password']);
        $this->grantInstantRefresh($intruder['email']);

        $client->request('POST', \sprintf('/api/bands/%d/setlist-refresh', $ownerFixture['band']->getId()), server: self::authHeaders($intruderLogin['accessToken']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('band_not_on_your_concerts', $body);

        unset($ownerLogin); // fixture setup only
    }

    public function testAcceptedTriggerReturns202AndASecondTriggerReturns200WithTheSameRefresh(): void
    {
        $client = $this->createAuthClient();
        $this->countingClient()->reset();
        $this->resetSetlistfmRedis();
        $credentials = $this->registerUser($client);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);
        $this->grantInstantRefresh($credentials['email']);
        $fixture = $this->createConcertWithBand($credentials['email']);
        $bandId = $fixture['band']->getId();

        $client->request('POST', \sprintf('/api/bands/%d/setlist-refresh', $bandId), server: self::authHeaders($login['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED, (string) $client->getResponse()->getContent());
        $first = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame('queued', $first['state']);
        self::assertSame(0, $this->countingClient()->getRequestCount(), 'AC-3.1: zero outbound calls on the request thread.');

        $client->request('POST', \sprintf('/api/bands/%d/setlist-refresh', $bandId), server: self::authHeaders($login['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
    }

    public function testGetOnABandNeverRefreshedReturns200WithStateNull(): void
    {
        $client = $this->createAuthClient();
        $this->resetSetlistfmRedis();
        $credentials = $this->registerUser($client);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);
        $fixture = $this->createConcertWithBand($credentials['email']);

        $client->request('GET', \sprintf('/api/bands/%d/setlist-refresh', $fixture['band']->getId()), server: self::authHeaders($login['accessToken']));

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertNull($data['state']);
    }

    /** AC-4.2/AC-4.5: the cooldown reason is reachable and carries a 429 + Retry-After. */
    public function testCooldownRefusalIs429WithRetryAfterAndTypedReason(): void
    {
        $client = $this->createAuthClient();
        $this->countingClient()->reset();
        $this->resetSetlistfmRedis();
        $credentials = $this->registerUser($client);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);
        $this->grantInstantRefresh($credentials['email']);
        $fixture = $this->createConcertWithBand($credentials['email']);
        $bandId = $fixture['band']->getId() ?? 0;

        // Seed the cooldown directly, then transition the in-flight record out of "active" so the
        // trigger reaches the cooldown check rather than returning "already in flight" (200).
        $coordinator = static::getContainer()->get(SetlistRefreshCoordinator::class);
        $coordinator->trigger($fixture['band'], $this->userEntity($credentials['email']), new \DateTimeImmutable());
        $coordinator->markSucceeded($bandId, Band::RESOLUTION_RESOLVED, [], \App\Service\Setlist\CachedFetch::live([], new \DateTimeImmutable()), new \DateTimeImmutable());

        $client->request('POST', \sprintf('/api/bands/%d/setlist-refresh', $bandId), server: self::authHeaders($login['accessToken']));

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS, (string) $client->getResponse()->getContent());
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame('cooldown_active', $data['refusedReason']);
        self::assertSame(0, $this->countingClient()->getRequestCount());
    }

    /** AC-6.6: an MBID never shown as a candidate is refused with 422 mbid_not_a_candidate. */
    public function testPickWithAnMbidNotAmongTheCandidatesIsRefused(): void
    {
        $client = $this->createAuthClient();
        $this->resetSetlistfmRedis();
        $credentials = $this->registerUser($client);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);
        $this->grantInstantRefresh($credentials['email']);
        $fixture = $this->createConcertWithBand($credentials['email']);

        $client->request(
            'POST',
            \sprintf('/api/bands/%d/setlist-refresh/resolution', $fixture['band']->getId()),
            server: array_merge(self::authHeaders($login['accessToken']), ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json']),
            content: json_encode(['selectedMbid' => 'never-shown-to-anyone'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $client->getResponse()->getContent());
        self::assertStringContainsString('mbid_not_a_candidate', (string) $client->getResponse()->getContent());
    }

    /** AC-6.8: picking against an already-resolved band is refused with 422 band_already_resolved. */
    public function testPickAgainstAnAlreadyResolvedBandIsRefused(): void
    {
        $client = $this->createAuthClient();
        $this->resetSetlistfmRedis();
        $credentials = $this->registerUser($client);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);
        $this->grantInstantRefresh($credentials['email']);
        $fixture = $this->createConcertWithBand($credentials['email']);

        $mbid = bin2hex(random_bytes(16));
        // Seed a refresh record whose candidate set actually includes this mbid — so the pick
        // clears the candidate-membership check and reaches the state precondition this test means
        // to assert, rather than failing earlier on mbid_not_a_candidate.
        $coordinator = static::getContainer()->get(SetlistRefreshCoordinator::class);
        $candidate = new \App\Service\Setlist\ArtistSearchCandidate($mbid, $fixture['band']->getName(), null, null, null);
        $coordinator->trigger($fixture['band'], $this->userEntity($credentials['email']), new \DateTimeImmutable());
        $coordinator->markSucceeded($fixture['band']->getId() ?? 0, Band::RESOLUTION_AMBIGUOUS, [$candidate], \App\Service\Setlist\CachedFetch::live([], new \DateTimeImmutable()), new \DateTimeImmutable());

        $fixture['band']->resolveTo($mbid, $fixture['band']->getName(), new \DateTimeImmutable());
        $this->entityManager()->flush();

        // A candidate-set MBID against an already-resolved band is refused on the state
        // precondition — the case another user's pick landed first (AC-6.8/AC-6.14).
        $client->request(
            'POST',
            \sprintf('/api/bands/%d/setlist-refresh/resolution', $fixture['band']->getId()),
            server: array_merge(self::authHeaders($login['accessToken']), ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json']),
            content: json_encode(['selectedMbid' => $mbid], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $client->getResponse()->getContent());
        self::assertStringContainsString('band_already_resolved', (string) $client->getResponse()->getContent());
    }

    /** AC-11.6: the full pick path end to end — ambiguous refresh -> candidate list -> pick -> resolved. */
    public function testAmbiguousRefreshThenPickResolvesTheBandAndDispatchesCompletion(): void
    {
        $client = $this->createAuthClient();
        $this->resetSetlistfmRedis();
        $credentials = $this->registerUser($client);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);
        $this->grantInstantRefresh($credentials['email']);
        $fixture = $this->createConcertWithBand($credentials['email']);
        $band = $fixture['band'];
        $bandId = $band->getId() ?? 0;

        $chosenMbid = bin2hex(random_bytes(16));
        $coordinator = static::getContainer()->get(SetlistRefreshCoordinator::class);
        $candidate = new \App\Service\Setlist\ArtistSearchCandidate($chosenMbid, $band->getName(), null, 'the right one', null);
        $otherCandidate = new \App\Service\Setlist\ArtistSearchCandidate(bin2hex(random_bytes(16)), $band->getName(), null, 'a different one', null);
        $coordinator->trigger($band, $this->userEntity($credentials['email']), new \DateTimeImmutable());
        $coordinator->markSucceeded($bandId, Band::RESOLUTION_AMBIGUOUS, [$candidate, $otherCandidate], \App\Service\Setlist\CachedFetch::live([], new \DateTimeImmutable()), new \DateTimeImmutable());

        // GET reports the candidates.
        $client->request('GET', \sprintf('/api/bands/%d/setlist-refresh', $bandId), server: self::authHeaders($login['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $getData = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertIsArray($getData['candidates']);
        self::assertCount(2, $getData['candidates']);

        // Pick.
        $this->countingClient()->reset();
        $client->request(
            'POST',
            \sprintf('/api/bands/%d/setlist-refresh/resolution', $bandId),
            server: array_merge(self::authHeaders($login['accessToken']), ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json']),
            content: json_encode(['selectedMbid' => $chosenMbid], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED, (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->countingClient()->getRequestCount(), 'AC-2.9/AC-6.10: the pick itself makes no outbound call.');

        $bandAfter = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Band::class)->find($bandId);
        self::assertInstanceOf(Band::class, $bandAfter);
        self::assertSame($chosenMbid, $bandAfter->getSetlistfmMbid(), 'AC-6.11: the chosen MBID is written through Band::resolveTo().');
    }

    protected function userEntity(string $email): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    protected function grantInstantRefresh(string $email): void
    {
        $user = $this->userEntity($email);
        $user->grantInstantRefresh(new \DateTimeImmutable());
        $this->entityManager()->flush();
    }

    /** @return array{concert: Concert, band: Band} */
    protected function createConcertWithBand(string $email): array
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $name = \sprintf('Refresh API Testers %s', uniqid('', true));
        $band = new Band($name, BandResolver::normalize($name), $now);
        $em->persist($band);

        $concert = new Concert($this->userEntity($email), $now, 'Europe/Madrid', $now, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);

        $em->flush();

        return ['concert' => $concert, 'band' => $band];
    }

    protected function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function countingClient(): CountingSetlistFmHttpClient
    {
        $client = static::getContainer()->get(CountingSetlistFmHttpClient::class);
        self::assertInstanceOf(CountingSetlistFmHttpClient::class, $client);

        return $client;
    }

    /** @return array<string, string> */
    protected static function authHeaders(string $accessToken): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken];
    }
}
