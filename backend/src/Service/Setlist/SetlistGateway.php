<?php

declare(strict_types=1);

namespace App\Service\Setlist;

/**
 * The only public entry point into setlist.fm (D-58, AC-6.5, R-5). Every other class in the app —
 * `BandIdentityResolver`, the API state providers, `app:setlist:refresh` — depends on this class,
 * never on `SetlistCache` or `SetlistFmClient` directly. `App\Tests\Unit\Service\Setlist\
 * SetlistGatewayIsOnlyDoorTest` statically scans `src/` outside `App\Service\Setlist\` to keep it
 * that way.
 */
final readonly class SetlistGateway
{
    public function __construct(
        private SetlistCache $cache,
    ) {
    }

    public function searchArtist(string $name): CachedFetch
    {
        return $this->cache->fetchArtistSearch($name);
    }

    /**
     * `$waitOverrideSeconds` lets `app:setlist:refresh` (the nightly job, D-62) wait longer for a
     * rate-limit token than a web request ever would.
     */
    public function fetchArtistSetlistsPage(string $mbid, int $page, ?float $waitOverrideSeconds = null): CachedFetch
    {
        return $this->cache->fetchArtistSetlistsPage($mbid, $page, $waitOverrideSeconds);
    }

    public function fetchSetlistDetail(string $setlistfmId): CachedFetch
    {
        return $this->cache->fetchSetlistDetail($setlistfmId);
    }
}
