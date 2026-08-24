<?php

declare(strict_types=1);

namespace App\Tests\Setlist;

use PHPUnit\Framework\Attributes\Group;

/**
 * AC-13.3, D-70: the ONE test in this repository that calls real setlist.fm. Excluded from the
 * default suite by `phpunit.xml.dist`'s `<groups><exclude>` and therefore from CI (D-2) — run it
 * manually, before a release, with a real `SETLISTFM_API_KEY`:
 *
 *   docker compose exec backend vendor/bin/phpunit --group live
 *
 * It exists to catch the day setlist.fm's response shape changes underneath the recorded fixtures
 * this suite otherwise relies on exclusively (`tests/Fixtures/setlistfm/`). It spends real budget
 * (one search request) — do not add more live tests, and do not run this on a schedule.
 */
#[Group('live')]
final class SetlistFmLiveSmokeTest extends SetlistIntegrationTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();

        $apiKey = $_ENV['SETLISTFM_API_KEY'] ?? getenv('SETLISTFM_API_KEY');
        if (!\is_string($apiKey) || !preg_match('/^[0-9a-f]{32}$/', $apiKey)) {
            self::markTestSkipped('SETLISTFM_API_KEY is not set to a real-looking key — set one in backend/.env.local to run this test.');
        }
    }

    public function testRealArtistSearchReturnsTheExpectedShape(): void
    {
        // The real SetlistGateway from the container — no mock, real setlist.fm, real budget spend.
        $gateway = self::getContainer()->get(\App\Service\Setlist\SetlistGateway::class);

        $fetch = $gateway->searchArtist('Radiohead');

        self::assertNotNull($fetch->payload, 'setlist.fm search must return a payload for a well-known band.');
        self::assertArrayHasKey('artist', $fetch->payload);
        self::assertIsArray($fetch->payload['artist']);
        self::assertNotEmpty($fetch->payload['artist'], 'Radiohead must be a known artist on setlist.fm.');

        $first = $fetch->payload['artist'][0];
        self::assertIsArray($first);
        self::assertArrayHasKey('mbid', $first);
        self::assertArrayHasKey('name', $first);
    }
}
