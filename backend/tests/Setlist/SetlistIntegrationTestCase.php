<?php

declare(strict_types=1);

namespace App\Tests\Setlist;

use App\Service\Setlist\SetlistCache;
use App\Service\Setlist\SetlistCacheMetrics;
use App\Service\Setlist\SetlistFmBudget;
use App\Service\Setlist\SetlistFmClient;
use App\Service\Setlist\SetlistGateway;
use App\Service\Setlist\SetlistNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Shared scaffolding for setlist.fm integration tests (docs/specs/2026-08-22-setlistfm-integration.md,
 * AC-13.5): every test in this suite runs against the real Redis and PostgreSQL from `compose.yaml`
 * — cache-tier promotion (AC-6.2) and the shared rate limiter (AC-7.1) are exactly the behaviours an
 * in-memory double would fake away.
 *
 * The HTTP layer is the one thing replaced: a `MockHttpClient` fed recorded fixtures
 * (`tests/Fixtures/setlistfm/`, AC-13.1) stands in for `setlistfm.client`, and every test built on
 * this base counts outbound requests through it.
 */
abstract class SetlistIntegrationTestCase extends KernelTestCase
{
    protected int $outboundRequestCount = 0;

    /** Clears every setlist.fm-namespaced Redis key so tests don't leak state into each other. */
    protected function resetSetlistfmRedis(): void
    {
        $redis = self::getContainer()->get('setlistfm.redis');
        $keys = $redis->keys('setlistfm:*');
        if ([] !== $keys) {
            $redis->del($keys);
        }
    }

    /**
     * Truncates the durable cache tier (AC-6.4's cache-key uniqueness is exactly what this suite
     * tests, so leftover rows from a previous test class in the same run would collide on insert)
     * and clears Doctrine's identity map so a stale in-memory entity never masks a fresh read.
     */
    protected function resetSetlistfmDatabase(): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement('TRUNCATE songs, setlists, setlist_cache RESTART IDENTITY CASCADE');
        $this->entityManager()->clear();
    }

    /**
     * Builds a fresh `SetlistGateway` wired to the real Redis/Postgres services from the container,
     * but a `MockHttpClient` returning `$responses` in order — each dequeued response increments
     * `$this->outboundRequestCount` (AC-6.4's "count outbound calls" assertion).
     *
     * @param list<MockResponse> $responses
     */
    protected function makeGateway(array $responses): SetlistGateway
    {
        $this->outboundRequestCount = 0;
        $countingClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$responses): MockResponse {
                ++$this->outboundRequestCount;
                $response = array_shift($responses);
                self::assertNotNull($response, 'MockHttpClient exhausted its queued responses — the code under test made more outbound calls than the test expected.');

                return $response;
            },
        );

        $budget = new SetlistFmBudget(
            $this->redis(),
            $this->clock(),
            new \Psr\Log\NullLogger(),
            ratePerSecond: 100,
            dailyBudget: 1_000_000,
            tokenWaitSeconds: 1.0,
        );

        $client = new SetlistFmClient($countingClient, $budget, new \Psr\Log\NullLogger(), 'unused-in-tests');

        $cache = new SetlistCache(
            $this->redis(),
            self::getContainer()->get(\App\Repository\SetlistCacheEntryRepository::class),
            $client,
            self::getContainer()->get(SetlistCacheMetrics::class),
            new LockFactory(new FlockStore(sys_get_temp_dir())),
            $this->clock(),
            cacheTtl: 300,
            tokenWaitSeconds: 1.0,
        );

        return new SetlistGateway($cache);
    }

    protected function redis(): \Redis
    {
        return self::getContainer()->get('setlistfm.redis');
    }

    protected function clock(): ClockInterface
    {
        return self::getContainer()->get(ClockInterface::class);
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function normalizer(): SetlistNormalizer
    {
        return self::getContainer()->get(SetlistNormalizer::class);
    }

    protected static function fixture(string $name): string
    {
        $path = \dirname(__DIR__).'/Fixtures/setlistfm/'.$name;
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, "Missing fixture: {$path}");

        return $contents;
    }

    protected static function fixtureResponse(string $name, int $statusCode = 200): MockResponse
    {
        return new MockResponse(self::fixture($name), ['http_code' => $statusCode]);
    }
}
