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

    /**
     * Instant setlist refresh (docs/specs/2026-08-27-instant-setlist-refresh.md, D-263). Callable
     * ONLY from the refresh handler/processors (AC-2.8, statically enforced by
     * `App\Tests\Unit\Service\Setlist\ForceLiveCallersAreRestrictedTest`) — never from a read path.
     */
    public function refreshArtistSearch(string $name): CachedFetch
    {
        return $this->cache->forceFetchArtistSearch($name);
    }

    /** See {@see self::refreshArtistSearch()} — same restriction, same reason (AC-2.6, AC-2.8). */
    public function refreshArtistSetlistsPageOne(string $mbid, ?float $waitOverrideSeconds = null): CachedFetch
    {
        return $this->cache->forceFetchArtistSetlistsPageOne($mbid, $waitOverrideSeconds);
    }
}
