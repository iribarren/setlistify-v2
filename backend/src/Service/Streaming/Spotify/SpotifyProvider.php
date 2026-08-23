<?php

declare(strict_types=1);

namespace App\Service\Streaming\Spotify;

use App\Service\Streaming\Model\PlaylistDraft;
use App\Service\Streaming\Model\ProviderPlaylist;
use App\Service\Streaming\Model\ProviderTokens;
use App\Service\Streaming\Model\SongQuery;
use App\Service\Streaming\StreamingProviderInterface;

/**
 * The reference adapter (`docs/architecture.md` §4, `docs/external-apis.md` §Spotify) — the only
 * class in the app that knows this provider exists (D-82). `key()` returns the lowercase string
 * `'spotify'`; that string is data, not a symbol, so it appears freely elsewhere (entity columns,
 * fixtures) without tripping the architecture test.
 *
 * `createPlaylist()` always creates under the authenticated user's own account (`/v1/me/playlists`)
 * — the provider account id needed for that comes from `/v1/me`, fetched once as part of
 * {@see self::exchangeCode()} and never re-fetched per playlist (the caller already has it on the
 * linked `StreamingAccount`, but this adapter method only receives `ProviderTokens`, not the
 * account, so it re-resolves via `/v1/me` — one extra call per playlist creation, accepted cost).
 */
final class SpotifyProvider implements StreamingProviderInterface
{
    public const string KEY = 'spotify';

    private const int ADD_TRACKS_BATCH_SIZE = 100; // Spotify's own limit per request (AC-11.5).

    public function __construct(
        private readonly SpotifyHttpClient $httpClient,
        private readonly SpotifyErrorMapper $errorMapper,
        private readonly SpotifyTrackMapper $trackMapper,
        private readonly SpotifyQueryBuilder $queryBuilder,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $accountsBaseUrl,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function authorizationUrl(string $state, string $redirectUri, ?string $codeChallenge = null): string
    {
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => SpotifyScopes::asSpaceSeparatedString(),
        ];

        if (null !== $codeChallenge) {
            $params['code_challenge_method'] = 'S256';
            $params['code_challenge'] = $codeChallenge;
        }

        return \sprintf('%s/authorize?%s', rtrim($this->accountsBaseUrl, '/'), http_build_query($params));
    }

    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier = null): ProviderTokens
    {
        $formParams = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];
        if (null !== $codeVerifier) {
            $formParams['code_verifier'] = $codeVerifier;
            $formParams['client_id'] = $this->clientId;
        }

        $response = $this->httpClient->postForm(
            'token_exchange',
            '/api/token',
            $formParams,
            $this->tokenEndpointHeaders($codeVerifier),
            \Closure::fromCallable([$this->errorMapper, 'mapTokenEndpointError']),
        );

        $tokens = $this->tokensFromResponse($response);
        $identity = $this->fetchIdentity($tokens->accessToken);

        return new ProviderTokens(
            accessToken: $tokens->accessToken,
            refreshToken: $tokens->refreshToken,
            expiresAt: $tokens->expiresAt,
            scopes: $tokens->scopes,
            providerAccountId: $identity['id'],
            providerDisplayName: $identity['displayName'],
        );
    }

    public function refreshToken(ProviderTokens $tokens): ProviderTokens
    {
        if (null === $tokens->refreshToken) {
            throw new \LogicException('Cannot refresh a ProviderTokens with no refresh token.');
        }

        $response = $this->httpClient->postForm(
            'token_refresh',
            '/api/token',
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $tokens->refreshToken,
                'client_id' => $this->clientId,
            ],
            $this->tokenEndpointHeaders(null),
            \Closure::fromCallable([$this->errorMapper, 'mapTokenEndpointError']),
        );

        $refreshed = $this->tokensFromResponse($response, fallbackScopes: $tokens->scopes);

        // AC-4.4: a refresh response that omits a new refresh token keeps the existing one.
        return new ProviderTokens(
            accessToken: $refreshed->accessToken,
            refreshToken: $refreshed->refreshToken ?? $tokens->refreshToken,
            expiresAt: $refreshed->expiresAt,
            scopes: $refreshed->scopes,
        );
    }

    public function searchTrack(SongQuery $query, ProviderTokens $tokens): array
    {
        $response = $this->httpClient->get(
            'search',
            '/search',
            $this->queryBuilder->build($query),
            $tokens->accessToken,
        );

        return $this->trackMapper->mapSearchResponse($response, $query);
    }

    public function createPlaylist(PlaylistDraft $draft, ProviderTokens $tokens): ProviderPlaylist
    {
        $identity = $this->fetchIdentity($tokens->accessToken);

        $response = $this->httpClient->postJson(
            'create_playlist',
            \sprintf('/users/%s/playlists', $identity['id']),
            [
                'name' => $draft->name,
                'description' => $draft->description ?? '',
                'public' => false, // D-87: private by default, always.
            ],
            $tokens->accessToken,
        );

        $id = $response['id'] ?? null;
        $name = $response['name'] ?? $draft->name;
        $externalUrl = \is_array($response['external_urls'] ?? null) ? ($response['external_urls']['spotify'] ?? null) : null;

        if (!\is_string($id) || !\is_string($name) || !\is_string($externalUrl)) {
            throw $this->errorMapper->mapTransportFailure();
        }

        return new ProviderPlaylist(providerPlaylistId: $id, name: $name, externalUrl: $externalUrl);
    }

    public function addTracks(string $playlistId, array $trackIds, ProviderTokens $tokens): void
    {
        // AC-11.5: the caller passes the full list; batching to the provider's limit is entirely
        // this adapter's concern.
        foreach (array_chunk($trackIds, self::ADD_TRACKS_BATCH_SIZE) as $batch) {
            $uris = array_map(static fn (string $id): string => 'spotify:track:'.$id, $batch);

            $this->httpClient->postJson(
                'add_tracks',
                \sprintf('/playlists/%s/tracks', $playlistId),
                ['uris' => $uris],
                $tokens->accessToken,
            );
        }
    }

    public function playlistEmbedUrl(string $playlistId): string
    {
        return \sprintf('https://open.spotify.com/embed/playlist/%s', $playlistId);
    }

    public function playlistDeepLink(string $playlistId): string
    {
        return \sprintf('https://open.spotify.com/playlist/%s', $playlistId);
    }

    /** @return array{id: string, displayName: ?string} */
    private function fetchIdentity(string $accessToken): array
    {
        $response = $this->httpClient->get('me', '/me', [], $accessToken);

        $id = $response['id'] ?? null;
        if (!\is_string($id)) {
            throw $this->errorMapper->mapTransportFailure();
        }

        $displayName = \is_string($response['display_name'] ?? null) ? $response['display_name'] : null;

        return ['id' => $id, 'displayName' => $displayName];
    }

    /** @param array<string, mixed> $response
     * @param list<string> $fallbackScopes */
    private function tokensFromResponse(array $response, array $fallbackScopes = []): ProviderTokens
    {
        $accessToken = $response['access_token'] ?? null;
        $expiresIn = $response['expires_in'] ?? null;
        if (!\is_string($accessToken) || !\is_int($expiresIn)) {
            throw $this->errorMapper->mapTransportFailure();
        }

        $refreshToken = \is_string($response['refresh_token'] ?? null) ? $response['refresh_token'] : null;
        $scopeString = \is_string($response['scope'] ?? null) ? $response['scope'] : null;
        $scopes = null !== $scopeString && '' !== $scopeString ? explode(' ', $scopeString) : $fallbackScopes;

        return new ProviderTokens(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            // AC-4.5: computed from expires_in at the time of THIS response, not request time.
            expiresAt: new \DateTimeImmutable(\sprintf('+%d seconds', $expiresIn)),
            scopes: $scopes,
        );
    }

    /** @return array<string, string> */
    private function tokenEndpointHeaders(?string $codeVerifier): array
    {
        // With a PKCE verifier present, Spotify accepts client_id alone in the body (no secret
        // needed — the verifier proves possession). Without one (the refresh grant, or a provider
        // registration that isn't PKCE-only), authenticate with Basic auth as a confidential client
        // (D-74 — Setlistify holds a client secret).
        if (null !== $codeVerifier) {
            return [];
        }

        return ['Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret)];
    }
}
