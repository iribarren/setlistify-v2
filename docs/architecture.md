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

**D-17 — Expo Router's web output stays a SPA; SEO is explicitly deferred**
(`docs/specs/2026-08-21-frontend-skeleton.md`). The frontend skeleton (prompt 03) ships Expo
Router's default single-page-app web output — no static rendering, no server. That is fine for
every screen this repository has today, all of them behind a login. Prompt 21's public concert
pages (shared, unauthenticated, expected to carry link previews when pasted into Slack/Twitter/etc.)
will need a rendering mode that produces real HTML per URL — static generation or a server — which
Expo Router also supports, but choosing between them now would mean deciding for a page that doesn't
exist yet. Recorded here (R-7 in the frontend-skeleton spec) so prompt 21 starts from this known
constraint instead of rediscovering it mid-implementation.

**D-18 — Web token storage: refresh token in an httpOnly cookie, access token in memory only**
(`docs/specs/2026-08-21-auth-and-accounts.md`). Of the three candidates (`localStorage`, in-memory
only, httpOnly cookie + memory), the third is the only one that changes the outcome of an XSS: it
cannot exfiltrate the 30-day refresh token, only ride the session while the page is open — the same
exposure every option has anyway. Costs accepted: the web app and API must be same-site
(`SameSite=Strict`), and the storage adapter carries one platform branch (`storage.web.ts` /
`storage.native.ts`). **Implementation note**: the cookie is scoped to `/api`, not narrowly to the
refresh endpoint as first described — `/api/logout` also has to read it to know which family to
revoke, and a `/api/token/refresh`-scoped cookie is never sent to a different path at all. Only
`RefreshProcessor` and `LogoutProcessor` ever read it, so the CSRF argument (every other endpoint is
pure bearer auth and never consults a cookie) is unaffected in practice.

**D-19 — Email verification is shipped, enforced by a flag, and off by default at MVP.** The full
flow (token, email, confirm, resend, `emailVerifiedAt`) ships now; enforcement is a single
`IS_EMAIL_VERIFIED` security attribute (`App\Security\Voter\EmailVerifiedVoter`) governed by
`AUTH_REQUIRE_VERIFIED_EMAIL`, default `false`. Flipping it on later is a config change, not a new
code path — the login processor already checks it and fails with the same generic 401 as a wrong
password either way, so enabling it can never become an enumeration oracle. Prompt 10 (streaming
account linking) is the natural place to turn it on first.

**D-20 — Mailpit in compose for development; a DSN-only mailer everywhere.** A `mailpit` service in
`compose.yaml` (SMTP on 1025, web UI on 8025) backs `MAILER_DSN=smtp://mailpit:1025` in dev.
Application code depends on `symfony/mailer` and the DSN only — no provider SDK — so choosing a
production provider is a secret-store change, not a code change. `test` uses the in-memory/null
transport so mailer assertions never reach a real service (D-2).

**D-21 — A custom refresh-token implementation, not `gesdinet/jwt-refresh-token-bundle`.** That
bundle stores tokens in plaintext and implements neither rotation nor reuse detection — the two
properties this feature exists for. `App\Entity\RefreshToken` + `App\Service\Security\
RefreshTokenService` own hashing, families, rotation and reuse detection directly, including a
grace-window mitigation (a token rotated less than 10 seconds ago is treated as a benign duplicate
rather than theft) so a dropped response or a race between tabs doesn't log a real user out.

**D-22 — `App\Entity\User` is never a writable API resource; DTOs bind every public payload.**
Registration binds `RegisterUserInput` (`email`, `password` — nothing else); `/api/me` returns a
`Me` DTO. This is what makes "no public endpoint can grant `ROLE_ADMIN`" structural rather than
defensive — there is no `roles` field anywhere in the public contract to filter or forget to filter.

**D-23 — Enumeration resistance is total on login and reset, deliberately partial on registration.**
Login and password-reset-request leak nothing — those are the endpoints an attacker scripts.
Registration against a taken email returns a distinguishable 422, a deliberate trade-off: hiding it
would mean accepting every signup and deferring all feedback to email, degrading the primary
conversion path to close a low-value oracle that's already rate-limited to 5/hour/IP.

**D-24 — A concert is a local calendar date plus an IANA timezone, and stays `upcoming` until the
end of its own local day** (`docs/specs/2026-08-21-concert-domain-api.md`). `Concert.date` is the
calendar date as printed on the ticket, in the venue's local time; `Concert.timezone` is that
venue's IANA identifier — never a fixed offset, which would be wrong twice a year across a DST
change. Status (`upcoming`/`past`) is never a stored flag: `App\Service\Concert\ConcertScheduler`
derives a UTC boundary instant, `Concert.pastAfter = (date + 1 day) at 00:00 in timezone`, on every
write, and status is the single indexed comparison `pastAfter <= now()`. The rule is anchored to the
concert's own timezone, never the viewer's — the same concert must read the same way in every tab.

**D-25 — Band dedup uses a deliberately simple normalization, and accepts false merges over false
splits.** `App\Service\Concert\BandResolver::normalize()`: trim → collapse whitespace → Unicode NFKD
→ strip combining marks → lowercase → strip a leading definite article (`the`, `los`, `las`, `el`,
`la`) → remove characters that are neither letters/digits nor whitespace. `Sigur Rós` and `AC/DC`
normalize to `sigur ros` and `acdc`. This will falsely merge distinct bands whose names collapse
alike and falsely split spellings it can't see through — accepted because a merge is visible and
fixable, while a split silently duplicates the setlist.fm identity work prompt 09 is about to invest.
Normalization is a service method, not a database function, so that identity work can replace the
rule later without touching a query; the same method backs both dedup and the `?band=` search filter
so the two can never drift apart.

**D-26 — Venue is a Doctrine embeddable value object, not an entity and not loose columns.**
`Venue { name, city, countryCode }` maps inline onto `concerts` and serializes as a nested `venue`
object. The API contract is already the shape a promoted `Venue` entity (prompt 24) would want, so
that promotion is additive (the JSON gains an `id`), not breaking.

**D-27 — Ownership is enforced by a Doctrine query extension first, a voter second — the pattern
every later user-scoped resource copies.** `App\Security\ConcertOwnerExtension` adds
`WHERE owner = :current_user` to both collection and item queries, so a cross-owner item lookup
finds nothing and produces the framework's ordinary 404 — byte-identical to a genuinely missing id.
A voter alone would return 403, which confirms the id exists; the query extension is what makes
existence itself unobservable. `App\Security\Voter\ConcertVoter` is the second gate, checked after
the (already owner-filtered) entity is loaded, so a future code path that reaches a `Concert` outside
this query still fails closed. See §11's note on this convention.

**D-28 — Money is integer minor units plus an ISO 4217 code, never a float.**
`{ "amount": 4500, "currency": "EUR" }` is €45.00; the currency's exponent decides the scale, not a
hardcoded 2. Survives JSON round-trips exactly, needs no arbitrary-precision type client-side, and
formats correctly per currency via `Intl.NumberFormat` with no server-side formatting logic.

**D-29 — A resource with DTOs never binds request input to the entity, on read or write.**
`ConcertResource`'s operations declare `input`/`output` DTOs (`ConcertInput`, `ConcertPatchInput`,
`ConcertOutput`) and a custom state provider/processor per operation, continuing D-22. `status` and
the ordered lineup exist only as computed `ConcertOutput` values; `owner` is stamped server-side from
the security token in the processor, never read from the payload.

**D-30 — `note` is a plain-text field with no rendering contract.** One nullable `TEXT` column,
length-bounded (2000 chars), stored and returned verbatim, never parsed as HTML/Markdown by the API.
Prompt 20 (notes and reviews) owns rendering and can migrate the column into a richer model without
an API break, because nothing today depends on its contents being anything but a string.

**D-31 — Concert-domain bounds are set at launch, not retrofitted after abuse:** lineup 1–30 bands,
band name 1–120 characters, note ≤ 2000 characters, page size ≤ 100, date within
[1900-01-01, now + 5 years]. Validation constants in one place (`App\ApiResource\ConcertInput` /
`ConcertPatchInput`, `App\Validator\ConcertDateRange`), easy to raise later.

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
  through public registration — that is a test, not a convention. As of
  `docs/specs/2026-08-21-auth-and-accounts.md`, this is in fact the case: `bin/console
  app:admin:create <email> [<password>]` creates or promotes a user, `RegisterUserInput` (the entire
  registration request surface) has no `roles` field to attack, and `NoPublicRolesInOpenApiTest`
  fails the build if any public write operation's schema ever grows one.
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
      │      (date, timezone,     (billingOrder)   (name, normalizedName,
      │       pastAfter [derived],                  setlistfmMbid [null,
      │       venue [embedded],                      prompt 09])
      │       priceAmount/Currency,
      │       doorsTime/startTime,
      │       note)
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

`Concert`, `Band` and `ConcertBand` are built (`docs/specs/2026-08-21-concert-domain-api.md`, D-24–
D-31) — the sketch above now matches the code: `ConcertBand.billingOrder` keeps a lineup ordered,
`Concert.pastAfter` is the derived boundary instant `App\Service\Concert\ConcertScheduler` computes
on every write, and `Band.setlistfmMbid` is the nullable column prompt 09 will populate without a
migration. Everything else in this sketch (`Playlist`, `StreamingAccount`, `SetlistCacheEntry`,
`ProviderSetting`, …) is still a later prompt.

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
- **User session model** (`docs/specs/2026-08-21-auth-and-accounts.md`, D-18/D-21): a short-lived
  (15 min) JWT access token, never persisted server-side, carrying only `sub` (user id), `roles`,
  `iat`, `exp`, `jti` — no email, no name. A 30-day refresh token rotates on every use, is stored
  **hashed** (SHA-256; the plaintext exists only in the response/cookie), and belongs to a *family*
  shared by every token descended from one login. Presenting an already-rotated token — outside a
  short grace window that absorbs dropped responses and racing tabs (R-3) — is treated as theft and
  revokes the entire family. Native stores the refresh token in `expo-secure-store`; web gets it
  **only** as an httpOnly, `Secure`, `SameSite=Strict` cookie (D-18) — never `localStorage`, never
  in a JS-readable response body.
- Passwords are hashed with Symfony's auto password hasher (no algorithm named in application code),
  checked against Symfony's compromised-password list at registration and reset, and a password
  reset revokes every refresh-token family for that user — one recovered account can't leave a
  stolen session alive elsewhere.
- Credential endpoints (login, registration, refresh, password reset, verification resend) are rate
  limited via Symfony RateLimiter on Redis, and **fail closed**: if the limiter's storage is
  unreachable, the request is rejected (429), never silently allowed through.
- Login and password-reset-request are enumeration-resistant by design — wrong password, unknown
  email and (when `AUTH_REQUIRE_VERIFIED_EMAIL` is on) an unverified account all produce one
  identical response. Registration is the one deliberate, documented exception (D-23).
- A Monolog processor redacts credential-shaped values (`password`, `token`, `refresh_token`,
  `authorization`, `set-cookie`, …) from every log record, on every channel, so a password never
  reaches a log aggregator even if a future call site logs its context carelessly.
- **User-scoped resources return 404, never 403, for another user's data** — `Concert` is the first
  one (`docs/specs/2026-08-21-concert-domain-api.md`, D-27) and sets the pattern every later
  user-scoped resource (playlists, notes) is expected to copy: a Doctrine query extension
  (`App\Security\ConcertOwnerExtension`) filters *every* query — collection and item — to the current
  owner first, so a cross-owner lookup finds nothing and produces the framework's ordinary "not
  found" 404, indistinguishable from a genuinely missing id. A voter
  (`App\Security\Voter\ConcertVoter`) is the second gate, checked after load, for any future code
  path that reaches the entity outside that filtered query. A 403 here would confirm the id exists —
  exactly what this rule exists to prevent.
