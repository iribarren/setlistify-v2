<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setlist;

use App\Entity\Band;
use App\Entity\Concert;
use App\Entity\User;
use App\Repository\SetlistCacheEntryRepository;
use App\Service\Concert\BandResolver;
use App\Service\Setlist\SetlistCache;
use App\Service\Setlist\SetlistCacheMetrics;
use App\Service\Setlist\SetlistFmBudget;
use App\Service\Setlist\SetlistFmClient;
use App\Service\Setlist\SetlistGateway;
use App\Service\Setlist\SetlistRefreshRunLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;

/**
 * US-10: `app:setlist:refresh` — scope (upcoming/recently-past bands only, AC-10.1), the lock
 * (AC-10.5), and the recorded outcome (AC-10.7).
 */
final class SetlistRefreshCommandTest extends KernelTestCase
{
    public function testRefreshesAnUpcomingBandAndRecordsTheOutcome(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $redis = $container->get('setlistfm.redis');
        $keys = $redis->keys('setlistfm:*');
        if ([] !== $keys) {
            $redis->del($keys);
        }

        $em = $container->get(EntityManagerInterface::class);
        // The refresh command's scope (AC-10.1) is *every* unresolved band attached to an
        // upcoming/recently-past concert — without a clean slate here, leftover bands/concerts
        // from other test classes sharing this database would also be in scope, consuming this
        // test's tiny mocked response queue unpredictably. Safe to do at the top of this command's
        // own test: CASCADE pulls in concert_bands/setlists/songs, and no assertion in this suite
        // depends on data created by another test class surviving.
        $em->getConnection()->executeStatement('TRUNCATE bands, concerts CASCADE');
        $em->clear();
        $bandName = 'Refresh Job Band '.bin2hex(random_bytes(6));
        $mbid = 'refresh-job-'.bin2hex(random_bytes(8));
        [$band] = $this->seedUpcomingConcertWithBand($em, $bandName);

        $this->overrideGateway($container, new MockHttpClient([
            self::searchResponse($bandName, $mbid),
            self::fixtureResponse('artist-setlists-large-index.json'),
        ]));

        \assert(self::$kernel instanceof \Symfony\Component\HttpKernel\KernelInterface);
        $application = new Application(self::$kernel);
        $command = $application->find('app:setlist:refresh');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        $em->clear();
        $reloaded = $em->getRepository(Band::class)->find($band->getId());
        self::assertInstanceOf(Band::class, $reloaded);
        self::assertSame($mbid, $reloaded->getSetlistfmMbid());

        $runLog = $container->get(SetlistRefreshRunLog::class);
        $lastRun = $runLog->lastRun();
        self::assertNotNull($lastRun);
        self::assertGreaterThanOrEqual(1, $lastRun['bandsAttempted']);
    }

    public function testASecondOverlappingRunExitsCleanlyWithoutDoubleSpending(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $lockFactory = $container->get(LockFactory::class);
        $lock = $lockFactory->createLock('setlistfm:refresh');
        self::assertTrue($lock->acquire(false), 'precondition: lock must be free at test start');

        \assert(self::$kernel instanceof \Symfony\Component\HttpKernel\KernelInterface);
        $application = new Application(self::$kernel);
        $command = $application->find('app:setlist:refresh');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('already in progress', $tester->getDisplay());

        $lock->release();
    }

    /** @return array{Band, Concert} */
    private function seedUpcomingConcertWithBand(EntityManagerInterface $em, string $bandName): array
    {
        $now = new \DateTimeImmutable();
        $user = new User('refresh.'.bin2hex(random_bytes(6)).'@example.test', 'unused-hash');
        $em->persist($user);

        $band = new Band($bandName, BandResolver::normalize($bandName), $now);
        $em->persist($band);

        $date = $now->modify('+10 days');
        $pastAfter = $date->modify('+1 day');
        $concert = new Concert($user, $date, 'UTC', $pastAfter, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);

        $em->flush();

        return [$band, $concert];
    }

    private static function searchResponse(string $name, string $mbid): MockResponse
    {
        $body = json_encode([
            'type' => 'artists',
            'itemsPerPage' => 20,
            'page' => 1,
            'total' => 1,
            'artist' => [['mbid' => $mbid, 'name' => $name, 'sortName' => $name, 'disambiguation' => '', 'url' => '']],
        ], \JSON_THROW_ON_ERROR);

        return new MockResponse($body, ['http_code' => 200]);
    }

    private static function fixtureResponse(string $name): MockResponse
    {
        $path = \dirname(__DIR__, 2).'/Fixtures/setlistfm/'.$name;
        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        return new MockResponse($contents, ['http_code' => 200]);
    }

    /**
     * Replaces the whole `SetlistGateway` service — the command's actual dependency — rather than
     * the scoped `setlistfm.client` HTTP client alone, which the container compiler inlines
     * straight into `SetlistFmClient`'s constructor call, bypassing any `container->set()`
     * override at runtime (same reasoning as `SetlistApiWebTestCase`).
     */
    private function overrideGateway(\Symfony\Component\DependencyInjection\ContainerInterface $container, MockHttpClient $httpClient): void
    {
        $redis = $container->get('setlistfm.redis');

        $budget = new SetlistFmBudget($redis, $container->get(ClockInterface::class), new \Psr\Log\NullLogger(), ratePerSecond: 1000, dailyBudget: 1_000_000, tokenWaitSeconds: 10.0);
        $client = new SetlistFmClient($httpClient, $budget, new \Psr\Log\NullLogger(), 'unused-in-tests');
        $cache = new SetlistCache(
            $redis,
            $container->get(SetlistCacheEntryRepository::class),
            $client,
            $container->get(SetlistCacheMetrics::class),
            $container->get(LockFactory::class),
            $container->get(ClockInterface::class),
            cacheTtl: 300,
            tokenWaitSeconds: 10.0,
        );

        $container->set(SetlistGateway::class, new SetlistGateway($cache));
    }
}
