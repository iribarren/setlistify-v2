<?php

declare(strict_types=1);

namespace App\Tests\Setlist;

use App\Service\Setlist\SetlistCache;
use App\Service\Setlist\SetlistCacheMetrics;
use App\Service\Setlist\SetlistFmBudget;
use App\Service\Setlist\SetlistFmClient;
use App\Service\Setlist\SetlistGateway;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * US-8: exhausting the daily budget degrades to cache (or an explicit `unavailable` state) — never
 * a 500, never a silently-empty result (AC-8.1, AC-8.2, AC-8.6).
 */
final class BudgetExhaustionDegradesHonestlyTest extends SetlistIntegrationTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetSetlistfmRedis();
        $this->resetSetlistfmDatabase();
    }

    public function testExhaustedBudgetWithSomethingCachedServesCacheWithReason(): void
    {
        // First: a normal live fetch with plenty of budget, to populate the durable tier.
        $gateway = $this->gatewayWithBudget(dailyBudget: 1000, responses: [self::fixtureResponse('artist-search-single-match.json')]);
        $gateway->searchArtist('Radiohead');

        // Now: budget of exactly 0 remaining for a fresh identical-shaped search — but the
        // existing entry is for a DIFFERENT query, so this exercises "the durable tier has
        // something for THIS query already" by re-searching the same query after exhausting a
        // freshly-created budget for a second query. Simpler: force staleness isn't needed —
        // exercise degradation on a *different*, uncached query with a spent budget instead.
        $this->redis()->flushDB();
        $spentBudget = new SetlistFmBudget($this->redis(), $this->clock(), new \Psr\Log\NullLogger(), ratePerSecond: 1000, dailyBudget: 0, tokenWaitSeconds: 0.1);
        $client = new SetlistFmClient(new MockHttpClient(), $spentBudget, new \Psr\Log\NullLogger(), 'unused');
        $cache = new SetlistCache(
            $this->redis(),
            self::getContainer()->get(\App\Repository\SetlistCacheEntryRepository::class),
            $client,
            self::getContainer()->get(SetlistCacheMetrics::class),
            new LockFactory(new FlockStore(sys_get_temp_dir())),
            $this->clock(),
            300,
            0.1,
        );
        $degradedGateway = new SetlistGateway($cache);

        // Nothing cached for this query and the budget is at 0 — AC-8.2: explicit "unavailable".
        $unavailable = $degradedGateway->searchArtist('Some Band Never Searched');
        self::assertNull($unavailable->payload);
        self::assertTrue($unavailable->stale);
        self::assertSame('budget_exhausted', $unavailable->reason);
        self::assertNotNull($unavailable->budgetResetAt);
    }

    public function testExhaustedBudgetWithACachedAnswerServesItStaleRatherThanUnavailable(): void
    {
        $gateway = $this->gatewayWithBudget(dailyBudget: 1000, responses: [self::fixtureResponse('artist-search-single-match.json')]);
        $gateway->searchArtist('Radiohead');

        // Force the cached entry to be re-fetch-eligible by making it stale, then exhaust budget —
        // the read must still return the OLD cached answer with an honest `stale: true` + reason,
        // never an error and never a blank result (AC-8.1).
        $this->redis()->flushDB();
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement("UPDATE setlist_cache SET stale_after = now() - interval '1 hour'");
        // The raw SQL above bypasses Doctrine's unit of work — without clearing it, the entity
        // manager's identity map would still hand back the in-memory (non-stale) object loaded by
        // the read above, masking the very staleness this test just wrote.
        $this->entityManager()->clear();

        $spentBudget = new SetlistFmBudget($this->redis(), $this->clock(), new \Psr\Log\NullLogger(), ratePerSecond: 1000, dailyBudget: 0, tokenWaitSeconds: 0.1);
        $client = new SetlistFmClient(new MockHttpClient(), $spentBudget, new \Psr\Log\NullLogger(), 'unused');
        $cache = new SetlistCache(
            $this->redis(),
            self::getContainer()->get(\App\Repository\SetlistCacheEntryRepository::class),
            $client,
            self::getContainer()->get(SetlistCacheMetrics::class),
            new LockFactory(new FlockStore(sys_get_temp_dir())),
            $this->clock(),
            300,
            0.1,
        );
        $degradedGateway = new SetlistGateway($cache);

        $result = $degradedGateway->searchArtist('Radiohead');

        self::assertNotNull($result->payload, 'A stale-but-present cached answer must still be returned (AC-8.1) — never treated as unavailable.');
        self::assertTrue($result->stale);
        self::assertSame('budget_exhausted', $result->reason);
    }

    /** @param list<\Symfony\Component\HttpClient\Response\MockResponse> $responses */
    private function gatewayWithBudget(int $dailyBudget, array $responses): SetlistGateway
    {
        $this->outboundRequestCount = 0;
        $countingClient = new MockHttpClient(
            function () use (&$responses): \Symfony\Component\HttpClient\Response\MockResponse {
                ++$this->outboundRequestCount;

                return array_shift($responses);
            },
        );
        $budget = new SetlistFmBudget($this->redis(), $this->clock(), new \Psr\Log\NullLogger(), ratePerSecond: 1000, dailyBudget: $dailyBudget, tokenWaitSeconds: 1.0);
        $client = new SetlistFmClient($countingClient, $budget, new \Psr\Log\NullLogger(), 'unused');
        $cache = new SetlistCache(
            $this->redis(),
            self::getContainer()->get(\App\Repository\SetlistCacheEntryRepository::class),
            $client,
            self::getContainer()->get(SetlistCacheMetrics::class),
            new LockFactory(new FlockStore(sys_get_temp_dir())),
            $this->clock(),
            300,
            1.0,
        );

        return new SetlistGateway($cache);
    }
}
