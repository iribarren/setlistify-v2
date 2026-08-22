<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setlist;

use App\Repository\SetlistCacheEntryRepository;
use App\Service\Setlist\SetlistCache;
use App\Service\Setlist\SetlistCacheMetrics;
use App\Service\Setlist\SetlistFmBudget;
use App\Service\Setlist\SetlistFmClient;
use App\Service\Setlist\SetlistGateway;
use App\Tests\Functional\Auth\AuthWebTestCase;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;

/**
 * Shared scaffolding for the setlist.fm API endpoints (`/api/band-searches`,
 * `/api/bands/{id}/setlists`, `/api/setlists/{id}`). Replaces the whole `SetlistGateway` service
 * (the state providers' actual dependency) with one wired to a `MockHttpClient`, so these
 * end-to-end tests exercise the real routing/security/serialization stack without ever dialling
 * out (AC-13.1) — `phpunit.xml.dist`'s `SETLISTFM_BASE_URL` already points at an unreachable host
 * as a second line of defence.
 *
 * Overriding the scoped `setlistfm.client` HTTP client alone does not work here: it is a container
 * *alias*, and Symfony's compiler inlines a private, single-consumer service reference straight
 * into `SetlistFmClient`'s constructor call — bypassing `container->get()` (and therefore
 * `container->set()`) entirely at runtime. Replacing the top-level `SetlistGateway` service sidesteps
 * that; API Platform resolves state providers (and their constructor dependencies) through its own
 * service locator, which does perform a real `get()` per request.
 *
 * `KernelBrowser::request()` reboots the kernel (and therefore rebuilds the container from
 * scratch) before every request by default — which would silently discard the override above the
 * moment the caller's *next* `$client->request()` runs. `mockSetlistfmTransport()` calls
 * `$client->disableReboot()` for exactly this reason; every test using it must call it before its
 * final, assertion-bearing request.
 */
abstract class SetlistApiWebTestCase extends AuthWebTestCase
{
    /**
     * @param list<MockResponse> $responses
     */
    protected function mockSetlistfmTransport(KernelBrowser $client, array $responses): void
    {
        $client->disableReboot();
        $container = static::getContainer();

        $redis = $container->get('setlistfm.redis');
        \assert($redis instanceof \Redis);
        $keys = $redis->keys('setlistfm:*');
        if ([] !== $keys) {
            $redis->del($keys);
        }

        // The AC-13.4 fixtures use fixed setlist.fm ids/query text. D-59 makes a setlist immutable
        // once fetched — correct in production, but it means a leftover row from an earlier run of
        // this same test class (this suite doesn't reset the database between runs) would silently
        // answer from cache/an unrelated band instead of exercising a fresh live fetch.
        $em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE songs, setlists, setlist_cache RESTART IDENTITY CASCADE');
        $em->clear();

        $budget = new SetlistFmBudget($redis, $container->get(ClockInterface::class), new \Psr\Log\NullLogger(), ratePerSecond: 1000, dailyBudget: 1_000_000, tokenWaitSeconds: 1.0);
        $client = new SetlistFmClient(new MockHttpClient($responses), $budget, new \Psr\Log\NullLogger(), 'unused-in-tests');
        $cache = new SetlistCache(
            $redis,
            $container->get(SetlistCacheEntryRepository::class),
            $client,
            $container->get(SetlistCacheMetrics::class),
            $container->get(LockFactory::class),
            $container->get(ClockInterface::class),
            cacheTtl: 300,
            tokenWaitSeconds: 1.0,
        );

        $container->set(SetlistGateway::class, new SetlistGateway($cache));
    }

    protected static function fixture(string $name): string
    {
        $path = \dirname(__DIR__, 2).'/Fixtures/setlistfm/'.$name;
        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        return $contents;
    }

    protected static function fixtureResponse(string $name, int $statusCode = 200): MockResponse
    {
        return new MockResponse(self::fixture($name), ['http_code' => $statusCode]);
    }

    /** @return array{email: string, accessToken: string} */
    protected function registerAndLogin(KernelBrowser $client): array
    {
        $creds = $this->registerUser($client);
        $tokens = $this->loginUser($client, $creds['email'], $creds['password']);

        return ['email' => $creds['email'], 'accessToken' => $tokens['accessToken']];
    }

    /** Creates a concert with a single-band lineup and returns that band's id (via the existing `BandResolver` seam). */
    protected function createBandViaConcert(KernelBrowser $client, string $accessToken, ?string $bandName = null): int
    {
        $client->request(
            'POST',
            '/api/concerts',
            server: [
                'CONTENT_TYPE' => 'application/ld+json',
                'HTTP_ACCEPT' => 'application/ld+json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
            ],
            content: json_encode([
                'date' => '2026-12-24',
                'timezone' => 'Europe/Madrid',
                'lineup' => [['name' => $bandName ?? \sprintf('Band %s', bin2hex(random_bytes(6)))]],
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        $lineup = $data['lineup'];
        self::assertIsArray($lineup);
        $entry = $lineup[0];
        self::assertIsArray($entry);
        $band = $entry['band'];
        self::assertIsArray($band);
        $id = $band['id'];
        self::assertIsInt($id);

        return $id;
    }
}
