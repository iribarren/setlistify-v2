<?php

declare(strict_types=1);

namespace App\Tests\Setlist;

use App\Entity\Band;
use App\Message\RefreshBandSetlistsMessage;
use App\MessageHandler\RefreshBandSetlistsHandler;
use App\Repository\BandRepository;
use App\Service\Concert\BandResolver;
use App\Service\Setlist\BandIdentityResolver;
use App\Service\Setlist\SetlistNormalizer;
use App\Service\Setlist\SetlistRefreshCoordinator;
use App\Service\Setlist\SetlistRefreshMetrics;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * The async refresh handler (docs/specs/2026-08-27-instant-setlist-refresh.md, AC-2.7, AC-3.7,
 * AC-6.12): request-count caps and terminal-state recording.
 */
final class RefreshBandSetlistsHandlerTest extends SetlistIntegrationTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetSetlistfmRedis();
        $this->resetSetlistfmDatabase();
    }

    /** AC-2.7: an unresolved band that resolves and then fetches page 1 spends AT MOST 2 requests. */
    public function testAPlainTriggerOnAnUnresolvedBandThatResolvesSpendsAtMostTwoRequests(): void
    {
        $name = \sprintf('Handler Test %s', bin2hex(random_bytes(6)));
        $band = $this->persistBand($name);
        $mbid = bin2hex(random_bytes(16));

        $gateway = $this->makeGateway([
            self::searchResponse([['mbid' => $mbid, 'name' => $name]]),
            self::setlistsPageResponse([]),
        ]);
        $coordinator = $this->makeCoordinator();
        $handler = $this->makeHandler($gateway, $coordinator);

        $handler(new RefreshBandSetlistsMessage($band->getId() ?? 0, false));

        self::assertSame(2, $this->outboundRequestCount);
        $record = $coordinator->getRecord($band->getId() ?? 0);
        self::assertNotNull($record);
        self::assertSame('succeeded', $record->state);
        self::assertSame(Band::RESOLUTION_RESOLVED, $record->bandStateAfter);
    }

    /** AC-6.12: the pick's completion (identity already settled) issues AT MOST 1 request. */
    public function testIdentityAlreadySettledSkipsTheSearchAndFetchesOnlyTheIndexPage(): void
    {
        $name = \sprintf('Handler Settled %s', bin2hex(random_bytes(6)));
        $band = $this->persistBand($name);
        $band->resolveTo(bin2hex(random_bytes(16)), $name, new \DateTimeImmutable());
        $this->entityManager()->flush();

        $gateway = $this->makeGateway([self::setlistsPageResponse([])]);
        $coordinator = $this->makeCoordinator();
        $handler = $this->makeHandler($gateway, $coordinator);

        $handler(new RefreshBandSetlistsMessage($band->getId() ?? 0, true));

        self::assertSame(1, $this->outboundRequestCount);
        $record = $coordinator->getRecord($band->getId() ?? 0);
        self::assertNotNull($record);
        self::assertSame('succeeded', $record->state);
    }

    /** AC-6.1: an ambiguous outcome is a WORKED refresh — succeeded, with the candidates reported. */
    public function testAnAmbiguousOutcomeIsReportedAsSucceededWithCandidates(): void
    {
        $name = \sprintf('Handler Ambiguous %s', bin2hex(random_bytes(6)));
        $band = $this->persistBand($name);

        $gateway = $this->makeGateway([self::searchResponse([
            ['mbid' => bin2hex(random_bytes(16)), 'name' => $name, 'disambiguation' => 'one'],
            ['mbid' => bin2hex(random_bytes(16)), 'name' => $name, 'disambiguation' => 'two'],
        ])]);
        $coordinator = $this->makeCoordinator();
        $handler = $this->makeHandler($gateway, $coordinator);

        $handler(new RefreshBandSetlistsMessage($band->getId() ?? 0, false));

        self::assertSame(1, $this->outboundRequestCount, 'no index fetch is attempted for an ambiguous outcome');
        $record = $coordinator->getRecord($band->getId() ?? 0);
        self::assertNotNull($record);
        self::assertSame('succeeded', $record->state);
        self::assertSame(Band::RESOLUTION_AMBIGUOUS, $record->bandStateAfter);
        self::assertCount(2, $record->candidates);
    }

    /** AC-3.7: an upstream degradation is recorded as failed, with the reason. */
    public function testAnUpstreamDegradationDuringSearchIsRecordedAsFailed(): void
    {
        $name = \sprintf('Handler Degraded %s', bin2hex(random_bytes(6)));
        $band = $this->persistBand($name);

        $gateway = $this->makeGateway([new MockResponse('', ['http_code' => 500])]);
        $coordinator = $this->makeCoordinator();
        $handler = $this->makeHandler($gateway, $coordinator);

        $handler(new RefreshBandSetlistsMessage($band->getId() ?? 0, false));

        $record = $coordinator->getRecord($band->getId() ?? 0);
        self::assertNotNull($record);
        self::assertSame('failed', $record->state);
        self::assertNotNull($record->failureReason);
    }

    private function persistBand(string $name): Band
    {
        $band = new Band($name, BandResolver::normalize($name), new \DateTimeImmutable());
        $this->entityManager()->persist($band);
        $this->entityManager()->flush();

        return $band;
    }

    private function makeCoordinator(): SetlistRefreshCoordinator
    {
        $budget = new \App\Service\Setlist\SetlistFmBudget(
            $this->redis(),
            $this->clock(),
            new \Psr\Log\NullLogger(),
            ratePerSecond: 1000,
            dailyBudget: 1_000_000,
            tokenWaitSeconds: 1.0,
        );

        return new SetlistRefreshCoordinator(
            $this->redis(),
            $budget,
            self::getContainer()->get(SetlistRefreshMetrics::class),
            new LockFactory(new FlockStore(sys_get_temp_dir())),
            3600,
            5,
            0.10,
        );
    }

    private function makeHandler(\App\Service\Setlist\SetlistGateway $gateway, SetlistRefreshCoordinator $coordinator): RefreshBandSetlistsHandler
    {
        return new RefreshBandSetlistsHandler(
            self::getContainer()->get(BandRepository::class),
            new BandIdentityResolver($gateway, $this->normalizer(), $this->entityManager(), $this->clock()),
            $gateway,
            self::getContainer()->get(SetlistNormalizer::class),
            $coordinator,
            self::getContainer()->get(SetlistRefreshMetrics::class),
            $this->clock(),
            3.0,
        );
    }

    /** @param list<array{mbid: string, name: string, disambiguation?: string}> $candidates */
    private static function searchResponse(array $candidates): MockResponse
    {
        $artists = array_map(static fn (array $c): array => [
            'mbid' => $c['mbid'],
            'name' => $c['name'],
            'sortName' => $c['name'],
            'disambiguation' => $c['disambiguation'] ?? '',
            'url' => 'https://www.setlist.fm/setlists/test.html',
        ], $candidates);

        $body = json_encode([
            'type' => 'artists', 'itemsPerPage' => 20, 'page' => 1,
            'total' => \count($artists), 'artist' => $artists,
        ], \JSON_THROW_ON_ERROR);

        return new MockResponse($body, ['http_code' => 200]);
    }

    /** @param list<array<string, mixed>> $setlists */
    private static function setlistsPageResponse(array $setlists): MockResponse
    {
        $body = json_encode([
            'type' => 'setlists', 'itemsPerPage' => 20, 'page' => 1,
            'total' => \count($setlists), 'setlist' => $setlists,
        ], \JSON_THROW_ON_ERROR);

        return new MockResponse($body, ['http_code' => 200]);
    }
}
