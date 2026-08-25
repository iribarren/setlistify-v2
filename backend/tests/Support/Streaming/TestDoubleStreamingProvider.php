<?php

declare(strict_types=1);

namespace App\Tests\Support\Streaming;

use App\Service\Streaming\Exception\NotFoundException;
use App\Service\Streaming\Exception\QuotaExhaustedException;
use App\Service\Streaming\Exception\RateLimitedException;
use App\Service\Streaming\Exception\RegionRestrictedException;
use App\Service\Streaming\Exception\TokenExpiredException;
use App\Service\Streaming\Model\PlaylistDraft;
use App\Service\Streaming\Model\ProviderPlaylist;
use App\Service\Streaming\Model\ProviderTokens;
use App\Service\Streaming\Model\SongQuery;
use App\Service\Streaming\Model\TrackCandidate;
use App\Service\Streaming\StreamingProviderInterface;

/**
 * AC-9.5's proof: a second provider, registered ONLY by the `app.streaming_provider` tag
 * (`config/services.yaml`'s `when@test:` block below), discoverable through
 * `App\Service\Streaming\StreamingProviderLocator` and able to complete a
 * link -> search -> create-playlist path with zero modifications to any consuming class. Test-only
 * (`tests/Support/`) — never referenced from `src/`.
 *
 * Also carries the playlist-14 failure-injection hooks (spec 14 §8's "a queue of scripted
 * behaviours" shape): quota/rate-limit/region-restriction/vanished-track/token-expiry can each be
 * scripted per test via `script*()`, and every provider-facing call is counted so a test can assert
 * "no second createPlaylist() call" etc. Made `public: true` in `config/services.yaml`'s `when@test`
 * block so a test can fetch this exact instance from the container and script it before running the
 * real pipeline against it.
 */
final class TestDoubleStreamingProvider implements StreamingProviderInterface
{
    public const string KEY = 'test-double';

    private int $searchTrackCalls = 0;
    private int $createPlaylistCalls = 0;
    private int $addTracksCalls = 0;

    private ?int $quotaExhaustedAtSearchCall = null;
    private ?int $quotaExhaustedAtAddTracksCall = null;

    private ?int $rateLimitedAtSearchCall = null;
    private int $rateLimitFailuresRemaining = 0;
    private int $rateLimitRetryAfterSeconds = 1;

    private bool $refreshTokenExpires = false;

    /** @var list<string> */
    private array $vanishedTrackIds = [];
    /** @var list<string> */
    private array $regionRestrictedTrackIds = [];
    /** @var array<string, string> songTitle => providerTrackId override */
    private array $trackIdOverrides = [];
    /** @var list<string> songTitles for which searchTrack() returns zero candidates (F-09) */
    private array $noCandidateTitles = [];
    /** @var array<string, list<TrackCandidate>> songTitle => full scripted candidate list (docs/specs/2026-08-25-playlist-normal-mode.md) */
    private array $candidateOverrides = [];
    /** @var list<list<string>> */
    private array $addTracksCallLog = [];

    /** When true, `playlistEmbedUrl()` returns null — scripts "the adapter has no embed" (D-211). */
    private bool $embedUrlIsNull = false;

    /** Clears every scripted behaviour and call counter — call between tests sharing one kernel boot. */
    public function reset(): void
    {
        $this->searchTrackCalls = 0;
        $this->createPlaylistCalls = 0;
        $this->addTracksCalls = 0;
        $this->quotaExhaustedAtSearchCall = null;
        $this->quotaExhaustedAtAddTracksCall = null;
        $this->rateLimitedAtSearchCall = null;
        $this->rateLimitFailuresRemaining = 0;
        $this->rateLimitRetryAfterSeconds = 1;
        $this->refreshTokenExpires = false;
        $this->vanishedTrackIds = [];
        $this->regionRestrictedTrackIds = [];
        $this->trackIdOverrides = [];
        $this->noCandidateTitles = [];
        $this->candidateOverrides = [];
        $this->addTracksCallLog = [];
        $this->embedUrlIsNull = false;
    }

    /** `playlistEmbedUrl()` returns null on every subsequent call (D-211's "adapter returns null" case). */
    public function scriptEmbedUrlNull(): void
    {
        $this->embedUrlIsNull = true;
    }

    /**
     * Full control over `searchTrack()`'s return for one song title — used to script a CHOICE-band
     * (`0.55 <= confidence < 0.80`) or REJECT result, which the default single exact-match candidate
     * can never produce (docs/specs/2026-08-25-playlist-normal-mode.md).
     *
     * @param list<TrackCandidate> $candidates
     */
    public function scriptCandidates(string $songTitle, array $candidates): void
    {
        $this->candidateOverrides[$songTitle] = $candidates;
    }

    /** Throws `QuotaExhaustedException` from `searchTrack()` on exactly the given 1-based call number (a later retry's calls succeed normally). */
    public function scriptQuotaExhaustedAtSearchCall(int $callNumber): void
    {
        $this->quotaExhaustedAtSearchCall = $callNumber;
    }

    /** Throws `QuotaExhaustedException` from `addTracks()` on exactly the given 1-based call number (a later retry's calls succeed normally). */
    public function scriptQuotaExhaustedAtAddTracksCall(int $callNumber): void
    {
        $this->quotaExhaustedAtAddTracksCall = $callNumber;
    }

    /** Throws `RateLimitedException` on the given 1-based `searchTrack()` call, `$times` times before succeeding. */
    public function scriptRateLimitedAtSearchCall(int $callNumber, int $times, int $retryAfterSeconds = 0): void
    {
        $this->rateLimitedAtSearchCall = $callNumber;
        $this->rateLimitFailuresRemaining = $times;
        $this->rateLimitRetryAfterSeconds = $retryAfterSeconds;
    }

    /** `refreshToken()` throws `TokenExpiredException` (F-06 mid-run) instead of succeeding. */
    public function scriptRefreshTokenExpires(): void
    {
        $this->refreshTokenExpires = true;
    }

    /** The candidate returned for `$songTitle` carries `$providerTrackId` instead of the default. */
    public function scriptTrackId(string $songTitle, string $providerTrackId): void
    {
        $this->trackIdOverrides[$songTitle] = $providerTrackId;
    }

    /** `searchTrack()` returns zero candidates for `$songTitle` (F-09: "absent from the catalog"). */
    public function scriptNoCandidates(string $songTitle): void
    {
        $this->noCandidateTitles[] = $songTitle;
    }

    /** `addTracks()` throws `NotFoundException` (F-13) for a batch containing this track id. */
    public function scriptVanishedTrack(string $providerTrackId): void
    {
        $this->vanishedTrackIds[] = $providerTrackId;
    }

    /** `addTracks()` throws `RegionRestrictedException` (F-11) for a batch containing this track id. */
    public function scriptRegionRestrictedTrack(string $providerTrackId): void
    {
        $this->regionRestrictedTrackIds[] = $providerTrackId;
    }

    public function getSearchTrackCallCount(): int
    {
        return $this->searchTrackCalls;
    }

    public function getCreatePlaylistCallCount(): int
    {
        return $this->createPlaylistCalls;
    }

    public function getAddTracksCallCount(): int
    {
        return $this->addTracksCalls;
    }

    /** @return list<list<string>> every `addTracks()` call's track-id batch, in call order. */
    public function getAddTracksCallLog(): array
    {
        return $this->addTracksCallLog;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function authorizationUrl(string $state, string $redirectUri, ?string $codeChallenge = null): string
    {
        return \sprintf('https://double.invalid/authorize?state=%s&redirect_uri=%s&challenge=%s', urlencode($state), urlencode($redirectUri), urlencode((string) $codeChallenge));
    }

    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier = null): ProviderTokens
    {
        return new ProviderTokens(
            accessToken: 'double-access-'.$code,
            refreshToken: 'double-refresh-'.$code,
            expiresAt: new \DateTimeImmutable('+1 hour'),
            scopes: ['double-scope'],
            providerAccountId: 'double-account-1',
            providerDisplayName: 'Double Account',
        );
    }

    public function refreshToken(ProviderTokens $tokens): ProviderTokens
    {
        if ($this->refreshTokenExpires) {
            throw new TokenExpiredException('test-double: scripted refresh-token expiry (F-06).');
        }

        return new ProviderTokens(
            accessToken: 'double-access-refreshed',
            refreshToken: $tokens->refreshToken,
            expiresAt: new \DateTimeImmutable('+1 hour'),
            scopes: $tokens->scopes,
        );
    }

    public function searchTrack(SongQuery $query, ProviderTokens $tokens): array
    {
        ++$this->searchTrackCalls;

        if (null !== $this->quotaExhaustedAtSearchCall && $this->searchTrackCalls === $this->quotaExhaustedAtSearchCall) {
            throw new QuotaExhaustedException('test-double: scripted quota exhaustion (F-04) at searchTrack().');
        }

        if ($this->searchTrackCalls === $this->rateLimitedAtSearchCall && $this->rateLimitFailuresRemaining > 0) {
            --$this->rateLimitFailuresRemaining;

            throw new RateLimitedException('test-double: scripted rate limit (F-05) at searchTrack().', $this->rateLimitRetryAfterSeconds);
        }

        if (\in_array($query->songTitle, $this->noCandidateTitles, true)) {
            return [];
        }

        if (isset($this->candidateOverrides[$query->songTitle])) {
            return $this->candidateOverrides[$query->songTitle];
        }

        $trackId = $this->trackIdOverrides[$query->songTitle] ?? 'double-track-1';

        return [
            new TrackCandidate(
                providerTrackId: $trackId,
                title: $query->songTitle,
                artist: $query->bandName,
                album: null,
                durationMs: 180_000,
                isLive: false,
                isCover: false,
                confidence: 0.9,
            ),
        ];
    }

    public function createPlaylist(PlaylistDraft $draft, ProviderTokens $tokens): ProviderPlaylist
    {
        ++$this->createPlaylistCalls;

        return new ProviderPlaylist(
            providerPlaylistId: 'double-playlist-'.$this->createPlaylistCalls,
            name: $draft->name,
            externalUrl: \sprintf('https://double.invalid/playlists/double-playlist-%d', $this->createPlaylistCalls),
        );
    }

    public function addTracks(string $playlistId, array $trackIds, ProviderTokens $tokens): void
    {
        ++$this->addTracksCalls;
        $this->addTracksCallLog[] = $trackIds;

        if (null !== $this->quotaExhaustedAtAddTracksCall && $this->addTracksCalls === $this->quotaExhaustedAtAddTracksCall) {
            throw new QuotaExhaustedException('test-double: scripted quota exhaustion (F-04) at addTracks().');
        }

        foreach ($trackIds as $trackId) {
            if (\in_array($trackId, $this->vanishedTrackIds, true)) {
                throw new NotFoundException('test-double: scripted vanished track (F-13).');
            }
            if (\in_array($trackId, $this->regionRestrictedTrackIds, true)) {
                throw new RegionRestrictedException('test-double: scripted region-restricted track (F-11).');
            }
        }
    }

    public function playlistEmbedUrl(string $playlistId): ?string
    {
        if ($this->embedUrlIsNull) {
            return null;
        }

        return \sprintf('https://double.invalid/embed/%s', $playlistId);
    }

    public function playlistDeepLink(string $playlistId): string
    {
        return \sprintf('double://playlists/%s', $playlistId);
    }
}
