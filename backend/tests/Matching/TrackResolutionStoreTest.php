<?php

declare(strict_types=1);

namespace App\Tests\Matching;

use App\Repository\TrackResolutionRepository;
use App\Service\Matching\Cache\TrackResolutionStore;

/**
 * T-UNIT-13: Redis read-through, promotion on a durable hit, the three TTLs by outcome, and
 * deletion on `NotFoundException` at insert time (spec 12 §8, D-121).
 */
final class TrackResolutionStoreTest extends MatchingIntegrationTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetMatchingRedis();
        $this->resetMatchingDatabase();
    }

    public function testMissReturnsNull(): void
    {
        self::assertNull($this->store()->find('spotify', 1, 'sigur ros', 'saeglopur'));
    }

    public function testSaveThenFindHitsRedisWithoutTouchingPostgresAgain(): void
    {
        $store = $this->store();
        $store->save('spotify', 1, 'sigur ros', 'saeglopur', 'track-1', 0.91, 'matched', [['providerTrackId' => 'track-1']]);

        $resolved = $store->find('spotify', 1, 'sigur ros', 'saeglopur');

        self::assertNotNull($resolved);
        self::assertSame('track-1', $resolved->providerTrackId);
        self::assertSame('matched', $resolved->outcome);
        self::assertSame(0.91, $resolved->confidence);
    }

    public function testADurableHitPromotesIntoRedis(): void
    {
        $store = $this->store();
        $store->save('spotify', 1, 'sigur ros', 'saeglopur', 'track-1', 0.91, 'matched', []);

        // Simulate an expired Redis entry (front tier only) while the durable row is still good.
        $this->redis()->del('matching:resolution:spotify|1|sigur ros|saeglopur');

        $resolved = $store->find('spotify', 1, 'sigur ros', 'saeglopur');
        self::assertNotNull($resolved);

        // Promoted: a second find() must not need Postgres — proven by the key now existing in Redis.
        self::assertNotFalse($this->redis()->get('matching:resolution:spotify|1|sigur ros|saeglopur'));
    }

    public function testNegativeResultIsCached(): void
    {
        $store = $this->store();
        $store->save('spotify', 1, 'unknown band', 'unknown song', null, 0.0, 'not_found', []);

        $resolved = $store->find('spotify', 1, 'unknown band', 'unknown song');
        self::assertNotNull($resolved);
        self::assertNull($resolved->providerTrackId);
        self::assertSame('not_found', $resolved->outcome);
    }

    public function testTtlsDifferByOutcome(): void
    {
        $repository = self::getContainer()->get(TrackResolutionRepository::class);
        $store = $this->store();

        $store->save('spotify', 1, 'a', 'matched-song', 't1', 0.9, 'matched', []);
        $store->save('spotify', 1, 'a', 'choice-song', 't2', 0.6, 'matched_low_confidence', []);
        $store->save('spotify', 1, 'a', 'miss-song', null, 0.0, 'not_found', []);

        $matched = $repository->findOneByKey('spotify', 1, 'a', 'matched-song');
        $choice = $repository->findOneByKey('spotify', 1, 'a', 'choice-song');
        $miss = $repository->findOneByKey('spotify', 1, 'a', 'miss-song');

        self::assertNotNull($matched);
        self::assertNotNull($choice);
        self::assertNotNull($miss);

        $matchedDays = $matched->getResolvedAt()->diff($matched->getExpiresAt())->days;
        $choiceDays = $choice->getResolvedAt()->diff($choice->getExpiresAt())->days;
        $missDays = $miss->getResolvedAt()->diff($miss->getExpiresAt())->days;

        self::assertSame(180, $matchedDays);
        self::assertSame(60, $choiceDays);
        self::assertSame(30, $missDays);
    }

    public function testDeleteRemovesBothTiers(): void
    {
        $store = $this->store();
        $store->save('spotify', 1, 'a', 'vanished-song', 'gone-track', 0.9, 'matched', []);

        $store->delete('spotify', 1, 'a', 'vanished-song');

        self::assertNull($store->find('spotify', 1, 'a', 'vanished-song'));

        $repository = self::getContainer()->get(TrackResolutionRepository::class);
        self::assertNull($repository->findOneByKey('spotify', 1, 'a', 'vanished-song'));
    }

    private function store(): TrackResolutionStore
    {
        $store = self::getContainer()->get(TrackResolutionStore::class);

        return $store;
    }
}
