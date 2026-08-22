<?php

declare(strict_types=1);

namespace App\Tests\Support\Streaming;

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
 */
final class TestDoubleStreamingProvider implements StreamingProviderInterface
{
    public const string KEY = 'test-double';

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
        return new ProviderTokens(
            accessToken: 'double-access-refreshed',
            refreshToken: $tokens->refreshToken,
            expiresAt: new \DateTimeImmutable('+1 hour'),
            scopes: $tokens->scopes,
        );
    }

    public function searchTrack(SongQuery $query, ProviderTokens $tokens): array
    {
        return [
            new TrackCandidate(
                providerTrackId: 'double-track-1',
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
        return new ProviderPlaylist(
            providerPlaylistId: 'double-playlist-1',
            name: $draft->name,
            externalUrl: 'https://double.invalid/playlists/double-playlist-1',
        );
    }

    public function addTracks(string $playlistId, array $trackIds, ProviderTokens $tokens): void
    {
        // No-op: nothing to assert against for the test double beyond "did not throw".
    }

    public function playlistEmbedUrl(string $playlistId): string
    {
        return \sprintf('https://double.invalid/embed/%s', $playlistId);
    }

    public function playlistDeepLink(string $playlistId): string
    {
        return \sprintf('double://playlists/%s', $playlistId);
    }
}
