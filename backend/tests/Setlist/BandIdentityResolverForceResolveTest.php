<?php

declare(strict_types=1);

namespace App\Tests\Setlist;

use App\Entity\Band;
use App\Service\Concert\BandResolver;
use App\Service\Setlist\ArtistSearchCandidate;
use App\Service\Setlist\BandAlreadyResolvedException;
use App\Service\Setlist\BandIdentityResolver;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Instant setlist refresh (docs/specs/2026-08-27-instant-setlist-refresh.md, US-2):
 * `BandIdentityResolver::forceResolve()` and `resolveAmbiguousChoice()`.
 */
final class BandIdentityResolverForceResolveTest extends SetlistIntegrationTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetSetlistfmRedis();
        $this->resetSetlistfmDatabase();
    }

    /** AC-2.2/D-56: a `resolved` band's identity is never re-derived — zero outbound calls. */
    public function testForceResolveOnAnAlreadyResolvedBandNeverSearchesAgain(): void
    {
        $band = $this->persistBand(self::uniqueBandName('Already Resolved'));
        $band->resolveTo(self::uniqueMbid(), $band->getName(), new \DateTimeImmutable());

        $resolver = $this->makeResolver([]); // No responses queued — a search would exhaust the queue.

        $outcome = $resolver->forceResolve($band, new \DateTimeImmutable());

        self::assertSame(Band::RESOLUTION_RESOLVED, $outcome->state);
        self::assertSame(0, $this->outboundRequestCount);
    }

    /** AC-2.1: an `ambiguous` band is reset and genuinely re-searched, not replayed from cache. */
    public function testForceResolveOnAnAmbiguousBandResetsAndReSearches(): void
    {
        $name = self::uniqueBandName('Was Ambiguous');
        $band = $this->persistBand($name);
        $band->markAmbiguous(new \DateTimeImmutable());
        self::assertSame(Band::RESOLUTION_AMBIGUOUS, $band->getSetlistfmResolutionState());

        $mbid = self::uniqueMbid();
        $resolver = $this->makeResolver([self::searchResponse([['mbid' => $mbid, 'name' => $name]])]);

        $outcome = $resolver->forceResolve($band, new \DateTimeImmutable());

        self::assertSame(Band::RESOLUTION_RESOLVED, $outcome->state);
        self::assertSame($mbid, $band->getSetlistfmMbid());
        self::assertSame(1, $this->outboundRequestCount);
    }

    /** AC-2.1: a `no_presence` band is reset and re-searched too, without waiting 30 days. */
    public function testForceResolveOnANoPresenceBandResetsAndReSearches(): void
    {
        $name = self::uniqueBandName('Was No Presence');
        $band = $this->persistBand($name);
        $band->markNoPresence(new \DateTimeImmutable('-1 day'));

        $mbid = self::uniqueMbid();
        $resolver = $this->makeResolver([self::searchResponse([['mbid' => $mbid, 'name' => $name]])]);

        $outcome = $resolver->forceResolve($band, new \DateTimeImmutable());

        self::assertSame(Band::RESOLUTION_RESOLVED, $outcome->state);
        self::assertSame(1, $this->outboundRequestCount);
    }

    /** D-263: force-live skips the search cache's freshness check — a fresh cache entry is ignored. */
    public function testForceResolveIgnoresAFreshCacheEntryAndCallsLiveAnyway(): void
    {
        $name = self::uniqueBandName('Fresh Cache');
        $band = $this->persistBand($name);

        $firstMbid = self::uniqueMbid();
        $secondMbid = self::uniqueMbid();
        // Both responses queued upfront on one gateway/MockHttpClient: the first ordinary search
        // populates the cache tier; force-live must still reach the SECOND queued response rather
        // than replaying the (still fresh, staleAfter +1 day) first one.
        $gateway = $this->makeGateway([
            self::searchResponse([['mbid' => $firstMbid, 'name' => $name]]),
            self::searchResponse([['mbid' => $secondMbid, 'name' => $name]]),
        ]);

        $gateway->searchArtist($name); // ordinary, cached fetch — consumes response #1
        self::assertSame(1, $this->outboundRequestCount);

        $band->markAmbiguous(new \DateTimeImmutable()); // pretend the ordinary search found it ambiguous

        $resolver = new BandIdentityResolver($gateway, $this->normalizer(), $this->entityManager(), $this->clock());
        $outcome = $resolver->forceResolve($band, new \DateTimeImmutable());

        self::assertSame(Band::RESOLUTION_RESOLVED, $outcome->state);
        self::assertSame(2, $this->outboundRequestCount, 'force-live must have issued a second outbound call rather than serving the fresh cache entry');
        self::assertSame($secondMbid, $band->getSetlistfmMbid(), 'force-live must have skipped the fresh cache entry and reached the second queued response');
    }

    public function testResolveAmbiguousChoiceWritesThroughResolveToAndMakesNoOutboundCall(): void
    {
        $name = self::uniqueBandName('Picked');
        $band = $this->persistBand($name);
        $band->markAmbiguous(new \DateTimeImmutable());

        $candidate = new ArtistSearchCandidate(mbid: self::uniqueMbid(), name: 'Canonical Name', sortName: null, disambiguation: 'the right one', url: null);
        $resolver = $this->makeResolver([]); // AC-2.9: no outbound call — an empty queue proves it.

        $outcome = $resolver->resolveAmbiguousChoice($band, $candidate, new \DateTimeImmutable());

        self::assertSame(Band::RESOLUTION_RESOLVED, $outcome->state);
        self::assertSame($candidate->mbid, $band->getSetlistfmMbid());
        self::assertSame('Canonical Name', $band->getSetlistfmName());
        self::assertSame(0, $this->outboundRequestCount);
    }

    /** D-270/AC-6.8: the write is only ever into a vacancy — never overwrites an existing identity. */
    public function testResolveAmbiguousChoiceRefusesAnAlreadyResolvedBand(): void
    {
        $band = $this->persistBand(self::uniqueBandName('Already Has Identity'));
        $existingMbid = self::uniqueMbid();
        $band->resolveTo($existingMbid, 'Existing Name', new \DateTimeImmutable());

        $candidate = new ArtistSearchCandidate(mbid: self::uniqueMbid(), name: 'Someone Else', sortName: null, disambiguation: null, url: null);
        $resolver = $this->makeResolver([]);

        $this->expectException(BandAlreadyResolvedException::class);
        $resolver->resolveAmbiguousChoice($band, $candidate, new \DateTimeImmutable());
    }

    private function persistBand(string $name): Band
    {
        $band = new Band($name, BandResolver::normalize($name), new \DateTimeImmutable());
        $this->entityManager()->persist($band);
        $this->entityManager()->flush();

        return $band;
    }

    /** @param list<MockResponse> $responses */
    private function makeResolver(array $responses): BandIdentityResolver
    {
        $gateway = $this->makeGateway($responses);

        return new BandIdentityResolver($gateway, $this->normalizer(), $this->entityManager(), $this->clock());
    }

    private static function uniqueBandName(string $label): string
    {
        return \sprintf('%s %s', $label, bin2hex(random_bytes(6)));
    }

    private static function uniqueMbid(): string
    {
        return bin2hex(random_bytes(16));
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
            'type' => 'artists',
            'itemsPerPage' => 20,
            'page' => 1,
            'total' => \count($artists),
            'artist' => $artists,
        ], \JSON_THROW_ON_ERROR);

        return new MockResponse($body, ['http_code' => 200]);
    }
}
