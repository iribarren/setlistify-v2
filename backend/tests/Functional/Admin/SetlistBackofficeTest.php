<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Band;
use App\Entity\SetlistCacheEntry;
use App\Repository\AuditLogEntryRepository;

/**
 * US-11: the setlist.fm dashboard panel, the read-only cache list, and the two audited band writes
 * (AC-11.4, AC-11.5). AC-11.6/US-12: `SETLISTFM_API_KEY`'s value never appears in any rendered
 * admin screen.
 */
final class SetlistBackofficeTest extends AdminWebTestCase
{
    public function testDashboardAndCacheListNeverRenderTheApiKey(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $apiKey = $_ENV['SETLISTFM_API_KEY'] ?? getenv('SETLISTFM_API_KEY');
        self::assertIsString($apiKey);
        self::assertNotSame('', $apiKey);

        foreach (['/admin', '/admin/setlist-cache-entry', '/admin/band'] as $route) {
            $client->request('GET', $route);
            self::assertResponseIsSuccessful($route);
            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($apiKey, $html, "{$route} must never render SETLISTFM_API_KEY");
            self::assertStringNotContainsStringIgnoringCase('x-api-key', $html, "{$route} must never render the x-api-key header name");
        }
    }

    public function testSetlistCacheListShowsSeededEntries(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $em = static::getContainer()->get('doctrine')->getManager();
        $entry = new SetlistCacheEntry(
            cacheKey: 'artist.search:'.md5('artistName=BackofficeCrawlBand'.bin2hex(random_bytes(6))),
            endpoint: 'artist.search',
            payload: ['artist' => []],
            fetchedAt: new \DateTimeImmutable(),
            staleAfter: new \DateTimeImmutable('+1 day'),
            httpStatus: 200,
        );
        $em->persist($entry);
        $em->flush();

        $client->request('GET', '/admin/setlist-cache-entry');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('artist.search', (string) $client->getResponse()->getContent());
    }

    public function testCorrectMbidIsAppliedAndAudited(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $band = $this->persistBand();
        $newMbid = 'corrected-mbid-'.bin2hex(random_bytes(6));

        $client->request(
            'POST',
            "/admin/band/{$band->getId()}/correct-mbid",
            parameters: ['mbid' => $newMbid, '_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseRedirects();

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $reloaded = $em->getRepository(Band::class)->find($band->getId());
        self::assertInstanceOf(Band::class, $reloaded);
        self::assertSame($newMbid, $reloaded->getSetlistfmMbid());
        self::assertSame(Band::RESOLUTION_RESOLVED, $reloaded->getSetlistfmResolutionState());

        $auditRepo = static::getContainer()->get(AuditLogEntryRepository::class);
        $entries = $auditRepo->findBy(['action' => 'correct_band_mbid']);
        self::assertNotEmpty($entries, 'correcting an MBID must be audited (AC-11.5)');
    }

    public function testClearSetlistCacheRemovesRowsAndIsAudited(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $band = $this->persistBand();
        $em = static::getContainer()->get('doctrine')->getManager();
        $setlistRepository = static::getContainer()->get(\App\Repository\SetlistRepository::class);

        $setlist = new \App\Entity\Setlist(
            setlistfmId: 'clearcache'.bin2hex(random_bytes(4)),
            band: $band,
            eventDate: new \DateTimeImmutable('2020-01-01'),
            venueName: null,
            venueCity: null,
            venueCountry: null,
            tourName: null,
            fetchedAt: new \DateTimeImmutable(),
        );
        $em->persist($setlist);
        $em->flush();

        self::assertSame(1, $setlistRepository->countForBand($band));

        $client->request(
            'POST',
            "/admin/band/{$band->getId()}/clear-setlist-cache",
            parameters: ['_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseRedirects();

        self::assertSame(0, $setlistRepository->countForBand($band));

        $auditRepo = static::getContainer()->get(AuditLogEntryRepository::class);
        $entries = $auditRepo->findBy(['action' => 'clear_band_setlist_cache']);
        self::assertNotEmpty($entries, 'clearing a band\'s setlist cache must be audited (AC-11.5)');
    }

    private function persistBand(): Band
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $name = 'Backoffice Band '.bin2hex(random_bytes(6));
        $band = new Band($name, \App\Service\Concert\BandResolver::normalize($name), new \DateTimeImmutable());
        $em->persist($band);
        $em->flush();

        return $band;
    }
}
