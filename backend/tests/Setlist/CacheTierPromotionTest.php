<?php

declare(strict_types=1);

namespace App\Tests\Setlist;

/**
 * AC-6.2: a hit in the durable (PostgreSQL) tier promotes the entry into Redis, so a subsequent
 * read never touches PostgreSQL either.
 */
final class CacheTierPromotionTest extends SetlistIntegrationTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetSetlistfmRedis();
        $this->resetSetlistfmDatabase();
    }

    public function testDurableTierHitPromotesIntoRedis(): void
    {
        $gateway = $this->makeGateway([self::fixtureResponse('artist-search-single-match.json')]);
        $gateway->searchArtist('Radiohead');
        self::assertSame(1, $this->outboundRequestCount);

        // Simulate a fresh session: clear Redis (tier 1) only, leave the Postgres row untouched.
        $this->redis()->flushDB();

        $fetch = $gateway->searchArtist('Radiohead');
        self::assertSame(1, $this->outboundRequestCount, 'A durable-tier hit must not make an outbound call — the count must stay at the one call from above.');
        self::assertSame('cache', $fetch->source);

        // A THIRD read must now be a Redis hit (promoted) — verified indirectly: still zero
        // outbound, and this time we don't even need Postgres, only Redis. We can't directly probe
        // which tier answered without touching internals, so we assert the promotion side effect:
        // the Redis key now exists.
        $keys = $this->redis()->keys('setlistfm:cache:*');
        self::assertNotEmpty($keys, 'The durable-tier hit must have written the entry back into Redis (AC-6.2).');
    }
}
