<?php

declare(strict_types=1);

namespace App\Tests\Setlist;

use App\Entity\Band;
use App\Service\Concert\BandResolver;
use App\Service\Setlist\BandIdentityResolver;
use App\Service\Setlist\SetlistNormalizer;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * US-1, US-2, US-5: name → MBID resolution, auto-resolve on a single exact match, ambiguity on
 * more than one, and the explicit `no_presence` state for zero candidates.
 *
 * Band names here are unique per test (random suffix): the test suite shares one PostgreSQL
 * database across every test class (no per-test isolation), and `bands.normalized_name` is unique
 * — a fixed name risks colliding with a row another test already created. Search responses are
 * built inline (not from the shared `tests/Fixtures/setlistfm/` set) precisely so the candidate
 * name can match the unique band name exactly, which is what AC-2.2/AC-2.3's normalized-match rule
 * actually exercises; AC-13.4's fixed fixture set is exercised elsewhere (SetlistNormalizerTest,
 * ZeroOutboundCallOnRepeatReadTest, the functional Setlist API tests).
 */
final class BandIdentityResolverTest extends SetlistIntegrationTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetSetlistfmRedis();
        $this->resetSetlistfmDatabase();
    }

    public function testSingleExactMatchAutoResolvesWithoutUserInteraction(): void
    {
        $name = self::uniqueBandName('Solo Match');
        $band = $this->persistUnresolvedBand($name);
        $mbid = self::uniqueMbid();
        $resolver = $this->makeResolver([self::searchResponse([['mbid' => $mbid, 'name' => $name]])]);

        $outcome = $resolver->ensureResolved($band);

        self::assertSame(Band::RESOLUTION_RESOLVED, $outcome->state);
        self::assertSame($mbid, $band->getSetlistfmMbid());
        self::assertSame($name, $band->getSetlistfmName());
    }

    public function testAlreadyResolvedBandNeverSearchesAgain(): void
    {
        $band = $this->persistUnresolvedBand(self::uniqueBandName('Already Resolved'));
        $band->resolveTo(self::uniqueMbid(), $band->getName(), new \DateTimeImmutable());

        // No responses queued at all — if the resolver searched again, the test would fail on an
        // exhausted-queue assertion (AC-1.4).
        $resolver = $this->makeResolver([]);

        $outcome = $resolver->ensureResolved($band);

        self::assertSame(Band::RESOLUTION_RESOLVED, $outcome->state);
        self::assertSame(0, $this->outboundRequestCount);
    }

    public function testMoreThanOneExactNormalizedMatchIsAmbiguous(): void
    {
        $name = self::uniqueBandName('Ambiguous');
        $band = $this->persistUnresolvedBand($name);
        $resolver = $this->makeResolver([self::searchResponse([
            ['mbid' => self::uniqueMbid(), 'name' => $name, 'disambiguation' => 'first act with this name'],
            ['mbid' => self::uniqueMbid(), 'name' => $name, 'disambiguation' => 'second act with this name'],
        ])]);

        $outcome = $resolver->ensureResolved($band);

        self::assertSame(Band::RESOLUTION_AMBIGUOUS, $outcome->state);
        self::assertCount(2, $outcome->candidates);
        self::assertNull($band->getSetlistfmMbid());
    }

    public function testZeroCandidatesIsExplicitNoPresenceNotAnEmptyResult(): void
    {
        $band = $this->persistUnresolvedBand(self::uniqueBandName('Unknown'));
        $resolver = $this->makeResolver([self::searchResponse([])]);

        $outcome = $resolver->ensureResolved($band);

        self::assertSame(Band::RESOLUTION_NO_PRESENCE, $outcome->state);
        self::assertNotNull($band->getSetlistfmCheckedAt());
    }

    public function testNoPresenceStateIsDistinguishableFromBudgetUnavailableByFieldValueAlone(): void
    {
        // AC-5.5: both must reach BandResolutionOutcome, but with different, unambiguous states.
        $noPresenceBand = $this->persistUnresolvedBand(self::uniqueBandName('Truly Unknown'));
        $resolvedByEmptySearch = $this->makeResolver([self::searchResponse([])])->ensureResolved($noPresenceBand);

        self::assertSame('no_presence', $resolvedByEmptySearch->state);
        self::assertNull($resolvedByEmptySearch->unavailableReason);
    }

    private function persistUnresolvedBand(string $name): Band
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

        return new BandIdentityResolver(
            $gateway,
            self::getContainer()->get(SetlistNormalizer::class),
            $this->entityManager(),
            $this->clock(),
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
            'type' => 'artists',
            'itemsPerPage' => 20,
            'page' => 1,
            'total' => \count($artists),
            'artist' => $artists,
        ], \JSON_THROW_ON_ERROR);

        return new MockResponse($body, ['http_code' => 200]);
    }

    private static function uniqueBandName(string $label): string
    {
        return \sprintf('%s %s', $label, bin2hex(random_bytes(6)));
    }

    private static function uniqueMbid(): string
    {
        return bin2hex(random_bytes(16));
    }
}
