<?php

declare(strict_types=1);

namespace App\Tests\Matching;

use App\Entity\Band;
use App\Entity\Setlist;
use App\Entity\Song;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Shared scaffolding for `TrackResolutionStore`/`TrackMatcher` integration tests (spec 12 §8,
 * T-UNIT-13). Runs against the real Redis and PostgreSQL from `compose.yaml` — cache-tier
 * promotion and the TTL-by-outcome rule are exactly the behaviours an in-memory double would fake
 * away, mirroring `App\Tests\Setlist\SetlistIntegrationTestCase`'s posture for the same reason.
 */
abstract class MatchingIntegrationTestCase extends KernelTestCase
{
    protected function resetMatchingRedis(): void
    {
        $redis = self::getContainer()->get('matching.redis');
        $keys = $redis->keys('matching:*');
        if ([] !== $keys) {
            $redis->del($keys);
        }
    }

    protected function resetMatchingDatabase(): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement('TRUNCATE track_resolutions, songs, setlists, bands RESTART IDENTITY CASCADE');
        $this->entityManager()->clear();
    }

    protected function redis(): \Redis
    {
        $redis = self::getContainer()->get('matching.redis');

        return $redis;
    }

    protected function clock(): ClockInterface
    {
        return self::getContainer()->get(ClockInterface::class);
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function persistBand(string $name = 'Sigur Rós'): Band
    {
        $band = new Band($name, strtolower($name), new \DateTimeImmutable());
        $this->entityManager()->persist($band);
        $this->entityManager()->flush();

        return $band;
    }

    protected function persistSetlist(Band $band, string $setlistfmId = 'abc123'): Setlist
    {
        $setlist = new Setlist(
            $setlistfmId,
            $band,
            new \DateTimeImmutable('2023-07-12'),
            'Razzmatazz',
            'Barcelona',
            'ES',
            null,
            new \DateTimeImmutable(),
        );
        $this->entityManager()->persist($setlist);
        $this->entityManager()->flush();

        return $setlist;
    }

    protected function persistSong(
        Setlist $setlist,
        int $position,
        string $title,
        ?string $setLabel = null,
        ?string $coverOfName = null,
        bool $isTape = false,
        ?string $info = null,
    ): Song {
        $song = new Song($setlist, $position, $setLabel, $title, $coverOfName, null, null, $info, $isTape);
        $this->entityManager()->persist($song);
        $this->entityManager()->flush();

        return $song;
    }
}
