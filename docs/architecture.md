# Setlistify — Architecture

Status: **decided** (2026-08-21). Changes to anything in this document need a spec and a PR.

## 1. Stack

| Layer | Choice | Why |
|-------|--------|-----|
| Frontend | **Expo** (React Native + react-native-web), Expo Router, TypeScript | One codebase for web, iOS and Android. The concert tracker is the same product on all three; maintaining two UI layers would double the work for no user-visible gain. |
| Backend | **PHP 8.4 · Symfony 8.1 · API Platform 4.3** | Strong DI container, which is what makes the provider-adapter pattern cheap; API Platform generates the OpenAPI spec the client is built from; Messenger covers async playlist jobs without another runtime. |
| Database | **PostgreSQL** + Doctrine ORM | Relational domain (users → concerts → bands → setlists → playlists). JSONB for cached setlist.fm payloads. |
| Cache / queue | **Redis** | Setlist cache tier, provider-config cache, Messenger transport, rate-limiter storage. |
| Backoffice | **EasyAdmin 5.5** | Server-rendered admin inside the Symfony app. Zero admin code shipped to clients. |
| Local dev | **Docker Compose** | One command to bring up the real integration surface. |
| Production | **Managed PaaS** + platform secret store | No ops burden at MVP scale; a real secret manager from day one. |

Version floor: PHP ≥ 8.4 (Symfony 8.1 requires it), EasyAdmin ≥ 5.5 (first release supporting
Symfony 8). Symfony 8.4 will be the next LTS — plan the upgrade path, do not pin to 8.1 forever.

**Symfony 8.1 → 8.4 LTS upgrade path** (R-3, `docs/specs/2026-08-21-backend-skeleton.md`): 8.1
carries security support only until its own end of life; 8.4, when released, becomes the long-term
support line. The backend-skeleton feature verified that Symfony 8.1, API Platform 4.3 and Doctrine
resolve cleanly together on PHP 8.4 (R-1) — the same version floor 8.4-LTS will ship on, so the
upgrade is expected to be a `composer require symfony/symfony:8.4.*`-shaped version bump rather than
a platform migration. No entity or provider-adapter code exists yet to be broken by it; the earlier
this upgrade happens after 8.4 ships, the smaller that surface stays. Treat it as routine maintenance
to schedule, not a redesign.

### Decisions

**D-1 — PHP runtime: FrankenPHP.**
The stack runs as a single `backend` container built on FrankenPHP (`docker/backend/Dockerfile`)
rather than a paired nginx + PHP-FPM setup. One container instead of two removes an inter-container
networking surface and a second config file from local dev; it maps cleanly onto the managed-PaaS
production target above, which prefers one process on one port; and it is the runtime Symfony itself
now leads with. Worker mode is **not** enabled at MVP — classic request-per-process first, worker mode
revisited only if measurements justify it, since worker mode changes application-state assumptions
and there is no application yet to measure. The cost is lower team familiarity with FrankenPHP's Caddy
layer; the mitigation is that the alternative remains mechanical to adopt — swap the runtime stage, add
an nginx service — because nothing above the container depends on the choice.

**D-2 — CI runs no integration tests against real external APIs.**
CI (`.github/workflows/ci.yml`) exercises only local services (the `compose-build-and-healthy` job)
and, later, recorded fixtures — never setlist.fm, Spotify or YouTube directly. setlist.fm's standard
key allows **1,440 requests per day for the entire application**, not per user or per environment
(§5 above; `docs/env-vars.md`) — a CI job running on every push could consume the production budget and
take the product down. Provider quotas (YouTube units, Spotify rate limits) carry the same hazard.
Contract verification against live providers, when needed, is a deliberate manual or scheduled run
using dedicated test credentials, never a per-push job.

**D-3 — The frontend is not containerized.**
Deliberate, not an omission: Expo's native tooling (Metro bundler, device/simulator access, QR
pairing over the LAN) works poorly through container networking. `compose.yaml` defines no frontend
service; run it on the host (`README.md`).

## 2. Shape of the system

```
Expo client (web/iOS/Android)
      │  JSON over HTTPS, JWT bearer
      ▼
API Platform  ──────────────────────────────► OpenAPI spec ──► openapi-typescript ──► frontend/api/
      │
      ├── Service/Setlist/      ──► setlist.fm      (cached, rate-limited, 1440/day budget)
      ├── Service/Streaming/    ──► StreamingProviderInterface
      │        ├── Spotify/                          ─► Spotify Web API
      │        ├── YouTube/                          ─► YouTube Data API v3
      │        └── Apple/        (future)            ─► Apple Music API
      ├── Service/Provider/     ──► ProviderRegistry (reads ProviderSetting, Redis-cached)
      ├── Service/Matching/     ──► Song → Track resolution
      └── MessageHandler/       ──► async playlist builds (Messenger + Redis)

EasyAdmin  /admin  (separate firewall, session + 2FA, ROLE_ADMIN)
      └── reads every entity · writes ProviderSetting · appends AuditLogEntry
```

## 3. Backend layering

```
backend/src/
├─ Entity/            Doctrine entities — the domain, no framework logic
├─ Repository/        Query objects. All DB access goes through these.
├─ ApiResource/       API Platform resources + DTOs. The public contract.
├─ Controller/
│  └─ Admin/          EasyAdmin CRUD controllers. Never in the public spec.
├─ Service/
│  ├─ Setlist/        SetlistFmClient, SetlistCache, SetlistRateLimiter
│  ├─ Streaming/      StreamingProviderInterface + one directory per adapter
│  ├─ Provider/       ProviderRegistry, ProviderSettingWriter
│  ├─ Matching/       SongNormalizer, TrackMatcher, MatchConfidence
│  └─ Security/       TokenCipher (libsodium), AdminAuditLogger
├─ MessageHandler/    BuildPlaylistHandler, RefreshTokenHandler
└─ Message/           the message DTOs themselves
```

Rules that hold across the layers:

- Controllers and API resources contain no business logic — they validate, delegate, and shape a
  response.
- Only `Repository/` touches Doctrine's query layer.
- Only `Service/Streaming/<Provider>/` knows a provider exists. Everything upstream sees the
  interface.

## 4. The streaming port

The single most important abstraction in the codebase. Every provider is reached through it, and
every provider difference dies behind it.

```php
interface StreamingProviderInterface
{
    public function key(): string;                 // 'spotify' | 'youtube' | 'apple'

    // OAuth
    public function authorizationUrl(string $state, string $redirectUri): string;
    public function exchangeCode(string $code, string $redirectUri): ProviderTokens;
    public function refreshToken(ProviderTokens $tokens): ProviderTokens;

    // Catalog
    /** @return TrackCandidate[] ordered by descending confidence */
    public function searchTrack(SongQuery $query, ProviderTokens $tokens): array;

    // Playlists
    public function createPlaylist(PlaylistDraft $draft, ProviderTokens $tokens): ProviderPlaylist;
    public function addTracks(string $playlistId, array $trackIds, ProviderTokens $tokens): void;

    // Playback surface — see §7
    public function playlistEmbedUrl(string $playlistId): ?string;
    public function playlistDeepLink(string $playlistId): string;
}
```

`TrackCandidate` carries the provider's track id, title, artist, album, duration, whether it is a
live recording, and a normalized confidence score. That last field is what lets Fast mode pick
automatically and Normal mode present a ranked choice — the same data, two behaviours.

**Adding a provider** means: one directory under `Service/Streaming/`, one `ProviderSetting` row, one
entry in the OAuth redirect configuration, and its credentials in the secret store. Nothing else in
the codebase changes. This is the property that makes the Spotify user-cap survivable.

## 5. setlist.fm integration and the cache

The standard API key allows **2 requests/second and 1,440 requests/day for the entire application**.
That is the binding constraint on how many users Setlistify can serve, so caching is part of the
design rather than an optimization applied later.

Three tiers, checked in order:

1. **Redis**, short TTL (minutes) — absorbs repeat requests inside one user session.
2. **PostgreSQL** (`setlist_cache`, JSONB payload + fetched_at) — the durable tier. A band's past
   setlists are immutable history; once fetched, they never need re-fetching. Only "has this band
   played since?" queries need refreshing, and those are date-bounded.
3. **setlist.fm** — reached only on a miss in both, through a token-bucket rate limiter (2/s) that
   also enforces the daily budget and fails soft when it is exhausted.

When the daily budget is spent, the app serves cached data and tells the user plainly that fresh
setlists are unavailable until tomorrow. It does not silently return an empty result.

## 6. Provider configuration (backoffice-controlled)

`ProviderSetting`, one row per provider:

| Field | Type | Meaning |
|-------|------|---------|
| `provider` | string, unique | `spotify` \| `youtube` \| `apple` |
| `enabled` | bool | Offered to users at all |
| `playbackMode` | enum | `embed` \| `deeplink` \| `off` — how a playlist is played on the concert page |
| `isDefault` | bool | Pre-selected when a user has more than one linked account |
| `notes` | text | Free-text operational note, admin-only |

**Credentials are not in this table and never will be.** Client IDs and secrets come from the secret
store. `docs/env-vars.md` defines the boundary.

`ProviderRegistry` is the only read path, Redis-cached with explicit invalidation on write. It is
consulted when:

- listing providers a user may link,
- choosing a provider for playlist generation,
- rendering the playback surface on a concert page,
- serving the public `GET /api/config/providers` endpoint the client reads at startup.

Disabling a provider is a **graceful** operation, not a kill: existing linked accounts and generated
playlists remain, the concert page shows a "temporarily unavailable" state, and nothing 500s. This is
the intended response to YouTube exhausting its 10k units/day mid-afternoon.

## 7. Playback surface

The concert page plays a generated playlist through the provider's own iframe embed. The decision is
**runtime configuration, not code**: `playbackMode` selects embed, a deep-link handoff, or nothing.

This matters legally, not just operationally. Spotify's developer policy distinguishes a *Streaming
SDA* (plays Spotify audio — no commercial use permitted at all) from a *Non-Streaming SDA* (creates
playlists, hands off to Spotify to play — limited commercial uses permitted). Embedding plausibly
makes Setlistify the former. While the app is unmonetized this is harmless, because the prohibition
is on commercial uses and there are none. Flipping `playbackMode` to `deeplink` converts the app to
the latter without a deploy. See `docs/external-apis.md` §Spotify for the full position.

## 8. Playlist generation pipeline

Both modes share one pipeline; they differ only in who resolves ambiguity.

```
Concert ─► select Setlist ─► normalize Songs ─► match Tracks ─► create Playlist ─► report
            (auto│user)                          (auto│user)
```

Generation runs **asynchronously** via Messenger — a 25-song setlist is 25+ provider searches, far
past a sane HTTP timeout. The client opens a job, polls or subscribes for progress, and receives a
result that always includes an honest per-song outcome (matched / low-confidence / not found /
skipped). Jobs are resumable: Normal mode's user choices are persisted as they are made, so an
abandoned session can be picked up rather than restarted.

Failure is expected and enumerated, not exceptional: band unknown to setlist.fm, band known but no
songs recorded, song absent from the provider's catalog, only live/cover versions available, region
restriction, provider rate limit, token expired. Each has a defined user-facing behaviour. The spikes
(prompts 12 and 13) exist to design this properly before any of it is written.

## 9. Backoffice

Server-rendered EasyAdmin at `/admin`, inside the Symfony app, deliberately not in the Expo client:
no admin code ships to public clients, no admin route enters the OpenAPI spec, and the admin firewall
can use sessions and 2FA instead of the API's JWTs.

- **Separate firewall** from the API. Session-based login, `ROLE_ADMIN` required, TOTP second factor
  (`scheb/2fa`). The API's JWT firewall grants no admin access.
- **The owner account is provisioned by console command only.** `ROLE_ADMIN` must be unreachable
  through public registration — that is a test, not a convention.
- **Read views** for users, concerts, playlists, generation jobs and setlist-cache health, so
  operating the app never requires a database client.
- **Write access is narrow**: provider configuration, plus user-level administrative actions
  (suspend, delete on request). It does not become a general-purpose data editor.
- **Every write is audited.** `AuditLogEntry` records actor, entity, field, old → new, timestamp.
  Provider-config changes above all, since they alter the app's legal classification.
- **Personal data is minimized in list views** (partial email masking), with full detail behind an
  explicit, logged action.

## 10. Data model sketch

```
User ─┬─< Concert ─┬─< ConcertBand >─ Band
      │            └──< Playlist ─── ProviderPlaylistRef (provider, external id)
      ├─< StreamingAccount (provider, encrypted tokens, scopes, expiry)
      └─< AuditLogEntry (as actor, admin only)

Band ──< SetlistCacheEntry (setlist.fm id, event date, venue, JSONB payload, fetched_at)
              └──< Song (position, title, is_cover, cover_of_band, info)

Playlist ──< PlaylistTrack (song ref, provider track id, match confidence, outcome)

ProviderSetting (provider, enabled, playbackMode, isDefault, notes)
```

`PlaylistTrack.outcome` is what makes the "what we couldn't match" report possible — every song in
the source setlist has a row, including the ones that produced no track.

## 11. Security posture

- Per-user provider OAuth tokens are **encrypted at rest** (libsodium `xchacha20poly1305`) through a
  custom Doctrine type, so a database dump is not a set of live streaming credentials.
- App secrets live in Symfony's secrets vault locally and the PaaS secret store in production. Never
  in the repo; `.env.example` holds names and dummy values only.
- Separate OAuth app registrations for dev and prod, so a leaked development key cannot reach
  production data.
- The admin firewall is separate, 2FA-gated, and rate-limited.
- All external calls have timeouts, retry-with-backoff, and circuit-breaking; no external service is
  trusted to be up.
