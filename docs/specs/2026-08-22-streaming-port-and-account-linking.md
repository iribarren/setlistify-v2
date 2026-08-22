# Streaming Port and Account Linking

| | |
|---|---|
| **Spec ID** | `2026-08-22-streaming-port-and-account-linking` |
| **Backlog prompt** | `docs/prompts/10-streaming-port-and-account-linking.md` |
| **Command** | `/feature streaming-port-and-account-linking` |
| **Primary agents** | `backend-engineer` + `frontend-engineer` (one branch, one PR) |
| **Branch** | `feature/streaming-port-and-account-linking` |
| **Depends on** | `04` — auth and accounts (merged) · `08` — backoffice foundation (merged) |
| **Status** | **Approved** |

---

## Overview

This feature builds the abstraction every streaming provider will ever be reached through, and its
first implementation: a user links their Spotify account over OAuth, and their tokens are stored
encrypted and refreshed without them ever noticing.

The abstraction is the point. The adapter is the proof it works.

The reason the ordering matters is not aesthetic, it is commercial. Spotify's **Development Mode
caps the application at 5 allowlisted users**, and Extended Quota Mode requires a launched service
with **250,000 monthly active users** (`docs/external-apis.md` §Spotify). That is a wall, not a
hurdle: you cannot reach 250k users while capped at 5. Spotify is therefore permanently a *reference
adapter for dogfooding*, and the product's viability rests on a second, uncapped provider (YouTube,
prompt 18) being cheap to add.

"Cheap to add" is a property that is either designed in now or paid for later as a rewrite. Hence the
rule in `CLAUDE.md`, which this feature is the first real test of:

> **No `Spotify` symbol may appear anywhere outside `backend/src/Service/Streaming/Spotify/`.**

Everything else in the codebase — playlist generation (prompt 14), matching (prompt 12), the concert
page's playback surface (prompt 19) — talks to `StreamingProviderInterface` and to a
provider-agnostic error taxonomy. If that holds, prompt 18 is one new directory and one settings row.
If it leaks, prompt 18 is a refactor of everything written between now and then.

The second half of the feature is the users' credentials. A linked account is a live credential to
someone's Spotify account; a database dump must not be a set of usable streaming credentials
(`docs/architecture.md` §11). So tokens are encrypted at rest through a custom Doctrine type — not a
service someone has to remember to call — with a key id stored alongside each ciphertext so
`TOKEN_ENCRYPTION_KEY` can be rotated without a downtime migration.

What this feature deliberately does **not** ship is anything that turns a setlist into a playlist. It
ends at: an authenticated user has a linked, refreshing, encrypted Spotify account, and the port can
search a track and create a playlist when asked. Matching quality is prompt 12; generation is prompt
14; which providers are on and how playback renders is prompt 11.

### Load-bearing rules this spec does not reverse

| Rule | Source | How this feature honours it |
|---|---|---|
| The streaming port is the only way to reach a provider | `CLAUDE.md`, `docs/architecture.md` §4 | The interface is implemented exactly as sketched; a static architecture test fails the build on any `Spotify` symbol outside its directory (US-9, AC-9.4) |
| Provider credentials never leave the secrets layer | `CLAUDE.md`, `docs/env-vars.md` | `SPOTIFY_CLIENT_ID` / `SPOTIFY_CLIENT_SECRET` are injected into the adapter only, never logged, never rendered, never in a response (US-7) |
| The backoffice edits behaviour, never credentials | `CLAUDE.md` | This branch adds **no** credential field anywhere. The backoffice sees a linked account's existence and status, never its tokens (AC-7.4) |
| Provider state is read at runtime, not baked in | `CLAUDE.md`, `docs/architecture.md` §6 | Consumers resolve adapters through a locator, never by class name (D-72); a `ProviderAvailability` seam is consumed now so prompt 11's `ProviderSetting` is an additive change (D-86) |
| A user-scoped resource returns 404, never 403 | `CLAUDE.md`, D-27 | `StreamingAccount` is user-scoped and copies `Concert`'s shape exactly: an owner query extension first, a voter second (D-77) |
| Users' provider tokens are encrypted at rest | `docs/architecture.md` §11, `docs/env-vars.md` | Custom Doctrine type, libsodium `xchacha20poly1305`, key id per ciphertext (US-6, D-78) |
| CI runs no integration tests against real external APIs | `docs/architecture.md` D-2 | Recorded fixtures only; one `@group live` smoke test excluded from the default suite (US-12) |
| The OpenAPI spec is the single source of truth for endpoints | `CLAUDE.md` | Linking operations are declared as API Platform resources/operations; this spec lists no endpoint paths, and `frontend/api/schema.d.ts` is regenerated before client work |
| Tokens never reach a log | `CLAUDE.md`, auth spec | The existing Monolog redaction processor is extended with the provider-token shapes and asserted (AC-7.2) |

### Existing groundwork reused, not rebuilt

| Already in place | Where | Reused for |
|---|---|---|
| `User`, JWT access tokens, rotating refresh tokens, `ROLE_USER`-only registration (D-18–D-23) | prompt 04 | Every linking operation is authenticated as the current user; a linked account belongs to a `User` |
| `App\Service\Security\UserIdProvider` | `backend/src/Service/Security/` | Resolving the current owner in the link/callback/unlink paths without touching the security token in application code |
| `App\Service\Security\ClientPlatform` | `backend/src/Service/Security/` | Web-vs-native branching already exists for the refresh cookie (D-18); the same distinction decides the OAuth return route (D-75) |
| `App\Service\Security\RateLimiterGuard` + Redis limiters, **fail-closed** | `backend/src/Service/Security/` | Rate-limiting the link-start operation (AC-8.6) with the posture already established, not a new one |
| `App\Security\ConcertOwnerExtension` + `ConcertVoter` | `backend/src/Security/` | The exact pattern `StreamingAccountOwnerExtension` copies (D-77). `ConcertOwnerExtension` itself is **not** touched |
| Monolog credential-redaction processor | prompt 04 | Extended, not replaced, with provider token keys (AC-7.2) |
| `ADMIN_TOTP_ENCRYPTION_KEY` + its libsodium encryption of the TOTP secret (AC-5.3, prompt 08) | prompt 08 | The encryption approach is already proven in this codebase; this feature generalises it into a reusable Doctrine type (D-78) |
| `AuditLogger`, `AbstractAdminCrudController` (abstract `configureFields`, D-46) | `backend/src/Service/Admin/`, `backend/src/Controller/Admin/` | The backoffice view of linked accounts cannot leak a field by omission |
| `backend/src/Service/Streaming/README.md` reserving the directory and restating the rule | `backend/src/Service/Streaming/` | The port lands exactly where prompt 01 reserved space for it |
| `backend/src/Service/Provider/README.md` reserving `ProviderRegistry` | `backend/src/Service/Provider/` | D-86's availability seam lives here, so prompt 11 fills in the file that is already named |
| `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET`, `SPOTIFY_REDIRECT_URI`, `TOKEN_ENCRYPTION_KEY` | `docs/env-vars.md`, `backend/.env.example` | Already declared — this branch makes them functional |
| Expo Router route groups, `(app)/account.tsx`, auth-aware routing, `expo-secure-store` | `frontend/app/` | The link/unlink UI is a section of the existing account screen, not a new navigation area |
| `setlistify://` URL scheme registered in `frontend/app.json` | `frontend/` | Native return leg of the OAuth round trip (D-75) |

## Goals

| Goal | Success looks like |
|---|---|
| The port exists and is the only door | Every consumer of a provider type-hints `StreamingProviderInterface`; a test fails the build if a provider-specific symbol appears outside its adapter directory |
| A second provider is a directory, not a project | A test-double adapter registered only by its service tag is discoverable and usable end-to-end with **zero** changes to consuming code |
| A user can link Spotify from any platform | The OAuth round trip completes on web, iOS and Android and the account shows as linked |
| Staying linked is invisible | An access token that expires mid-use is refreshed before the call, transparently; the user sees nothing |
| Losing the link is legible | An irrecoverably invalid grant produces an explicit "reconnect" state with a one-tap path back, never a silent failure or a mystery error |
| A database dump is not a credential dump | Raw token columns are ciphertext; a test reads the column directly and asserts it |
| Keys can be rotated | A record written under a retired key id still decrypts after the active key changes |
| Provider failures speak one language | Callers handle `TokenExpired`, `RateLimited`, `QuotaExhausted`, `NotFound`, `RegionRestricted`, `ProviderUnavailable` — never an HTTP status, never a Spotify-shaped error |
| The callback cannot be forged | `state` is server-generated, single-use, bound to the user and expiring; PKCE verifier never leaves the server |
| Tokens are invisible | No API response, log line, exception page or admin screen contains an access or refresh token — asserted, not assumed |
| Green in CI without touching Spotify | The full suite passes offline against recorded fixtures |

## User Stories

### US-1 — Link a Spotify account

> As a **logged-in user**, I want to connect my Spotify account, so that Setlistify can later build
> playlists in the account I actually listen on.

**Acceptance criteria**

- **AC-1.1** An authenticated operation starts the flow for a given provider key and returns an
  authorization URL for the client to open. The URL is produced by the adapter's
  `authorizationUrl()` — the calling code never assembles a provider URL itself.
- **AC-1.2** The authorization request uses OAuth 2.0 Authorization Code **with PKCE**; the code
  verifier is generated server-side, stored with the pending link, and never sent to the client
  (D-74).
- **AC-1.3** The requested scopes are the minimum needed for this feature's capabilities and are
  declared in exactly one place inside the adapter: identity (`user-read-private`) and private
  playlist writing (`playlist-modify-private`). No scope is requested "for later" (D-88).
- **AC-1.4** The provider's callback is handled server-side: the backend exchanges the code, fetches
  the provider account id and display name, and persists a `StreamingAccount` for the current user.
- **AC-1.5** Completing the flow twice for the same user and provider **updates** the existing
  record rather than creating a second one — `(user, provider)` is unique (D-77).
- **AC-1.6** The flow completes on **web, iOS and Android**, verified manually on all three, with the
  return leg described in AC-1.7/AC-1.8 (D-75).
- **AC-1.7** On web the browser is redirected to the app's account screen with a one-time, opaque
  link-result reference; the client resolves it to a success/failure state through an authenticated
  read. No token, code or verifier appears in any URL the browser sees.
- **AC-1.8** On native the flow runs in an in-app auth session (`WebBrowser.openAuthSessionAsync`)
  and returns to the `setlistify://` deep link, carrying the same opaque reference and nothing else.
- **AC-1.9** Exactly **one** redirect URI per environment is registered with the provider — the
  backend's own callback (`SPOTIFY_REDIRECT_URI`, already declared). Platform differences are handled
  after the exchange, on our side (D-75).
- **AC-1.10** A user who abandons the flow (closes the browser, denies consent) returns to an
  unchanged account state and a plain "not connected" screen; the pending link record expires on its
  own.

### US-2 — See what is connected

> As a **logged-in user**, I want to see which streaming accounts I have connected and whether they
> are healthy, so that I know whether Setlistify can act on my behalf before I try to use it.

**Acceptance criteria**

- **AC-2.1** An authenticated read lists the current user's linked accounts, each carrying: provider
  key, provider display name, provider account id, granted scopes, linked-at timestamp, and a
  status.
- **AC-2.2** Status is one of `connected` | `needs_reauth` | `revoked_by_user`, derived from stored
  state — never computed by calling the provider on a page load.
- **AC-2.3** The response contains **no** token material of any kind, and no expiry value that would
  let a caller infer one (AC-7.1 asserts this at the serializer level).
- **AC-2.4** The list is filtered to the current owner *before* any authorization check runs, so
  another user's account id is a 404, never a 403 (D-77).
- **AC-2.5** The account screen renders the three statuses distinctly, using prompt 02's design
  tokens and components, with a 44×44 minimum touch target on every action.
- **AC-2.6** A user with no linked accounts sees an explicit empty state offering the connect action,
  not a blank panel.

### US-3 — Disconnect an account

> As a **logged-in user**, I want to disconnect a streaming account, so that Setlistify can no longer
> act on my behalf and holds nothing of mine.

**Acceptance criteria**

- **AC-3.1** An authenticated unlink operation deletes the `StreamingAccount` row, including both
  ciphertext token columns. No soft delete, no archived copy.
- **AC-3.2** Where the provider exposes token revocation, the adapter attempts it before deletion,
  best-effort and time-boxed; a failed revocation never blocks the deletion (D-81).
- **AC-3.3** **Spotify exposes no token revocation endpoint.** The Spotify adapter therefore reports
  the revocation as unsupported, and the UI tells the user plainly that removing Setlistify's access
  entirely also requires removing the app in their Spotify account settings, with a link (D-81).
- **AC-3.4** After unlinking, a subsequent read of the collection no longer contains the account, and
  any cached derived state for that user is invalidated.
- **AC-3.5** Unlinking another user's account id is a 404 (D-77).
- **AC-3.6** The client confirms the destructive action before sending it, and reflects the result
  optimistically with reconciliation on the server response, consistent with D-35's pattern from
  prompt 07.

### US-4 — Stay connected without noticing

> As a **logged-in user**, I want my connection to keep working without re-authorizing every hour, so
> that generating a playlist never fails for a reason I did not cause.

**Acceptance criteria**

- **AC-4.1** Access tokens are refreshed **proactively**: any use of an account whose access token
  expires within a configurable skew (`STREAMING_TOKEN_REFRESH_SKEW`, default 60s) refreshes first,
  then proceeds.
- **AC-4.2** Refresh is centralised in one service; **no consumer of the port calls
  `refreshToken()` itself**. Consumers ask for a usable account and receive valid tokens (D-79).
- **AC-4.3** Concurrent uses of the same account refresh **once**, not once per process — guarded by
  a per-account lock (`symfony/lock`); a test with parallel callers asserts a single refresh call
  against the fixture (D-79).
- **AC-4.4** A refresh response that omits a new refresh token (Spotify's usual behaviour) keeps the
  existing one; a refresh response that includes one replaces it. Neither case loses the link.
- **AC-4.5** A refreshed token is written back encrypted, with expiry recomputed from the provider's
  `expires_in` at the time of the response, not at request time.
- **AC-4.6** A test proves the user-visible path: an account whose stored access token is already
  expired completes a port operation successfully, with no error surfaced.

### US-5 — Be told when to reconnect

> As a **logged-in user**, I want to be told clearly when my connection has broken beyond repair, so
> that I can fix it in one action instead of guessing why nothing works.

**Acceptance criteria**

- **AC-5.1** A refresh that fails with an unrecoverable grant error (revoked, expired refresh token,
  scope withdrawn) sets the account's status to `needs_reauth` and clears the stored tokens; the
  record itself is **kept** so the user's connection history and UI affordance survive (D-80).
- **AC-5.2** A refresh that fails for a *transient* reason (network, 5xx, rate limit) does **not**
  change status — it raises the taxonomy error for the caller and leaves the account alone (D-80).
- **AC-5.3** A `needs_reauth` account renders as an explicit "Reconnect" state with a single action
  restarting the US-1 flow for the same provider, preserving the account row on success (AC-1.5's
  update path).
- **AC-5.4** No operation ever hard-fails with a generic error because of a broken link: a caller
  receives `TokenExpired` from the taxonomy and can decide (prompt 14 will show it as a resumable
  reason on a generation job).
- **AC-5.5** The status transition is covered by tests for both directions:
  `connected → needs_reauth` on an unrecoverable refresh, and `needs_reauth → connected` on a
  successful relink.

### US-6 — A database dump is not a set of credentials

> As the **operator**, I want stored provider tokens to be unreadable without the encryption key, so
> that a database leak is not a compromise of every user's Spotify account.

**Acceptance criteria**

- **AC-6.1** Access and refresh tokens are stored encrypted with libsodium `xchacha20poly1305`,
  applied through a **custom Doctrine type**, so persisting a token cannot be done unencrypted by
  forgetting to call something (D-78).
- **AC-6.2** A test reads the raw column with a direct SQL query (bypassing the ORM) and asserts the
  value is neither the plaintext token nor a decodable encoding of it.
- **AC-6.3** Each ciphertext carries a **key id** and a per-record nonce in its envelope; the format
  is versioned so a future scheme change is detectable rather than ambiguous.
- **AC-6.4** Key rotation works: with a new active key id configured and the previous key still
  present in the retired set, a record written under the old key **still decrypts**, and a record
  written after rotation uses the new key. A test covers both in one run (D-78).
- **AC-6.5** Decryption with no matching key id fails loudly with a typed error — never silently
  returns null, and never falls back to treating the ciphertext as plaintext.
- **AC-6.6** `TOKEN_ENCRYPTION_KEY` (and the retired-key set) are read from environment/secret
  storage only, never from the database, never from `ProviderSetting`.

### US-7 — Tokens are invisible

> As the **operator**, I want to be sure a token cannot be observed anywhere in the system, so that a
> log aggregator, a screenshot of the backoffice or a support session cannot leak one.

**Acceptance criteria**

- **AC-7.1** No API response contains an access or refresh token, an authorization code, a PKCE
  verifier or a client secret. A serialization test asserts the linked-account payload against an
  explicit allowlist of fields, so a new field cannot leak by being added (the D-46 principle).
- **AC-7.2** The existing Monolog redaction processor covers the provider-token shapes
  (`access_token`, `refresh_token`, `code`, `code_verifier`, `client_secret`) on every channel; a
  test logs a record containing each and asserts the output is redacted.
- **AC-7.3** The provider HTTP client never logs request bodies or `Authorization` headers, including
  on failure, and its exception messages carry no token material.
- **AC-7.4** The backoffice view of linked accounts shows user, provider, provider account id,
  display name, scopes, status and timestamps — and **no** token field, enumerated explicitly through
  `AbstractAdminCrudController`'s abstract `configureFields` (D-46).
- **AC-7.5** The backoffice has **no** write action on tokens. The only admin write is unlinking an
  account on a user's behalf, routed through `AuditLogger` like every other admin write (D-84).
- **AC-7.6** Debug/exception pages in non-prod environments do not render token values — verified by
  asserting the exception message content, not by trusting the environment.

### US-8 — The callback cannot be forged

> As the **operator**, I want a forged or replayed OAuth callback to be rejected, so that nobody can
> attach their own Spotify account to someone else's Setlistify user (or the reverse).

**Acceptance criteria**

- **AC-8.1** `state` is generated server-side from a cryptographically secure source, single-use, and
  bound to: the user id, the provider key, the client platform, the PKCE verifier and an expiry.
- **AC-8.2** Pending link state is stored in Redis with a short TTL (`STREAMING_LINK_STATE_TTL`,
  default 600s) and deleted on first use. A replayed callback with a consumed `state` is rejected.
- **AC-8.3** A missing, unknown, expired or mismatched `state` is rejected with a generic error that
  reveals nothing about which condition failed.
- **AC-8.4** A callback whose `state` belongs to a different user than the current session is
  rejected; a test asserts this explicitly with two users.
- **AC-8.5** The provider's error callback (`access_denied` and friends) is handled as a normal
  outcome — the user returns to a clean "not connected" state, no record is written, no exception is
  logged as an error.
- **AC-8.6** The link-start operation is rate limited per user through `RateLimiterGuard`, and
  **fails closed** if the limiter's storage is unreachable — the posture prompt 04 already set.
- **AC-8.7** The one-time link-result reference (AC-1.7) is itself single-use, short-lived, and
  resolvable only by the user it was issued to.

### US-9 — A second provider is one directory

> As a **developer adding YouTube in prompt 18**, I want to add a provider by creating a directory
> and registering a service, so that the existing codebase does not have to change to accommodate it.

**Acceptance criteria**

- **AC-9.1** `StreamingProviderInterface` is implemented exactly as sketched in
  `docs/architecture.md` §4 — `key()`, `authorizationUrl()`, `exchangeCode()`, `refreshToken()`,
  `searchTrack()`, `createPlaylist()`, `addTracks()`, `playlistEmbedUrl()`, `playlistDeepLink()` —
  with no provider-specific method added to it (D-71).
- **AC-9.2** Shared value objects live outside every adapter directory: `ProviderTokens`,
  `SongQuery`, `TrackCandidate` (provider track id, title, artist, album, duration, `isLive`,
  `isCover`, normalized confidence), `PlaylistDraft`, `ProviderPlaylist`. All are immutable and
  contain no provider-shaped fields.
- **AC-9.3** Adapters are registered by a service tag and resolved by `key()` through a locator; a
  request for an unknown key raises a typed domain error. **No consuming code references an adapter
  class** (D-72).
- **AC-9.4** A static architecture test fails if any `Spotify` symbol (class, interface, trait,
  enum, constant, function or type reference) appears in `backend/src/` outside
  `src/Service/Streaming/Spotify/`. The test scans the source tree and is part of the default suite —
  this is the enforcement, not a convention (D-82).
- **AC-9.5** A test-double adapter, registered **only** by adding a class with the tag, is
  discoverable through the locator and can complete a link → search → create-playlist path with
  **zero** modifications to any consuming class. The diff of that test proves AC-9.5's claim
  literally: one directory, one registration.
- **AC-9.6** The Spotify adapter's environment variables are bound in one dedicated configuration
  file. Configuration and `.env` naming are outside the symbol ban (they are not PHP symbols), but
  they are confined to that one file so the leak surface is a single reviewable location (D-82).
- **AC-9.7** `playlistEmbedUrl()` and `playlistDeepLink()` are implemented by the Spotify adapter and
  covered by tests, even though no caller uses them yet — prompt 19 consumes them, and an unimplemented
  interface method is how a port rots.

### US-10 — Provider failures speak one language

> As a **developer writing playlist generation**, I want every provider failure to arrive as one of a
> known, small set of errors, so that my code handles outcomes rather than HTTP statuses.

**Acceptance criteria**

- **AC-10.1** The taxonomy is exactly: `TokenExpired`, `RateLimited`, `QuotaExhausted`, `NotFound`,
  `RegionRestricted`, `ProviderUnavailable` — provider-agnostic, defined outside every adapter
  directory.
- **AC-10.2** Mapping from a provider's own failure shape happens **inside** the adapter. No HTTP
  status code, provider error code or provider exception escapes an adapter (D-73).
- **AC-10.3** A test drives each taxonomy case from a recorded Spotify fixture (401 expired, 429 with
  `Retry-After`, 404, 403 region/market, 5xx) and asserts the taxonomy type the caller receives.
- **AC-10.4** `RateLimited` carries the retry-after hint when the provider supplied one; the value is
  a plain integer of seconds, not a provider header.
- **AC-10.5** Anything the adapter cannot classify becomes `ProviderUnavailable` — an unclassified
  failure never escapes as a raw exception.
- **AC-10.6** All outbound provider calls carry an explicit timeout and bounded, jittered retries;
  a test asserts the retry ceiling (`docs/architecture.md` §11 — no external service is trusted to be
  up).

### US-11 — The port can actually do the job

> As a **developer of prompts 12 and 14**, I want search, playlist creation and track addition to
> work through the port today, so that the next features build on a proven seam instead of a stub.

**Acceptance criteria**

- **AC-11.1** `searchTrack(SongQuery, ProviderTokens)` returns an array of `TrackCandidate` ordered
  by descending confidence, populated from the provider's search response.
- **AC-11.2** The confidence score in this branch is deliberately **naive** (a documented,
  normalized 0–1 heuristic) and lives behind a single method so prompt 12 replaces it in one place.
  The spec records this as provisional so no later reader mistakes it for a designed ranking (D-83).
- **AC-11.3** `isLive` and `isCover` are populated from what the provider's metadata makes available,
  and are `false` rather than `null` when it says nothing — the field means "known live", not
  "unknown".
- **AC-11.4** `createPlaylist(PlaylistDraft, ProviderTokens)` creates a **private** playlist and
  returns a `ProviderPlaylist` with the provider playlist id, name and external URL.
- **AC-11.5** `addTracks()` adds track ids to an existing playlist, chunked to the provider's batch
  limit inside the adapter — the caller passes the full list and knows nothing about batching.
- **AC-11.6** An empty song list, a song with no candidates, and a search returning zero results are
  all normal outcomes (empty array), never exceptions — `CLAUDE.md`: generation degrades, it does not
  fail.
- **AC-11.7** These operations are exercised through the port in tests, from a caller that
  type-hints only `StreamingProviderInterface`.

### US-12 — Green in CI without calling Spotify

> As a **developer**, I want the whole suite to pass offline, so that CI does not depend on a
> five-user-capped external service and does not spend a real user's quota.

**Acceptance criteria**

- **AC-12.1** No test in the default suite makes a network call to Spotify (`docs/architecture.md`
  D-2).
- **AC-12.2** Recorded fixtures cover: authorization redirect, code exchange, refresh success,
  refresh with an unrecoverable grant error, `/me` identity, search with results, search with zero
  results, playlist create, add-tracks success, and each AC-10.3 error case.
- **AC-12.3** A single `@group live` smoke test performs a real round trip against a developer's
  allowlisted account, excluded from the default suite and run manually before a release (D-85).
- **AC-12.4** Fixtures are captured by hand from real responses, committed, and scrubbed of every
  token, id and email belonging to a real account.
- **AC-12.5** The architecture test (AC-9.4), the raw-column encryption test (AC-6.2) and the
  serialization allowlist test (AC-7.1) all run in the default suite — the three that protect the
  invariants a future contributor is most likely to break by accident.

## Technical Approach

### Sub-projects touched

| Sub-project | Work |
|---|---|
| `backend/` | The port, the value objects, the error taxonomy, the locator, the Spotify adapter, `StreamingAccount` + its owner extension and voter, the encryption Doctrine type, the token manager, the linking API operations, the backoffice panel |
| `frontend/` | Regenerated API types, the account screen's connections section, the platform-specific OAuth round trip, the reconnect path |
| `docs/` | `env-vars.md`, `architecture.md` (§4, §10, §11), `external-apis.md` if the revocation finding changes anything, `backend/.env.example` |

### Backend shape

```
src/Service/Streaming/
  StreamingProviderInterface.php        ← the port (docs/architecture.md §4, verbatim)
  StreamingProviderLocator.php          ← key() → adapter, via tagged services (D-72)
  Model/
    ProviderTokens.php  SongQuery.php  TrackCandidate.php
    PlaylistDraft.php   ProviderPlaylist.php
  Exception/
    StreamingException.php              ← common base
    TokenExpiredException.php  RateLimitedException.php  QuotaExhaustedException.php
    NotFoundException.php      RegionRestrictedException.php  ProviderUnavailableException.php
  Link/
    LinkFlowService.php                 ← start/complete, state + PKCE lifecycle (US-1, US-8)
    PendingLinkStore.php                ← Redis, single-use, TTL
    StreamingTokenManager.php           ← proactive refresh, single-flight lock (US-4, US-5)
  Spotify/                              ← the ONLY place the word appears (AC-9.4)
    SpotifyProvider.php  SpotifyHttpClient.php  SpotifyErrorMapper.php
    SpotifyTrackMapper.php  SpotifyScopes.php

src/Service/Provider/
  ProviderAvailability.php              ← interface; this branch's impl = "every registered adapter
                                          is available" (D-86). Prompt 11 replaces the impl only.

src/Doctrine/Type/
  EncryptedStringType.php               ← libsodium xchacha20poly1305, key id envelope (D-78)
src/Service/Security/
  TokenCipher.php                       ← active key + retired key set, versioned envelope

src/Entity/StreamingAccount.php
src/Security/StreamingAccountOwnerExtension.php   ← copies ConcertOwnerExtension (D-77)
src/Security/Voter/StreamingAccountVoter.php
```

### Data model addition

```
StreamingAccount
  id            uuid
  user          FK → User, ON DELETE CASCADE
  provider      string          ← 'spotify' | … (the port's key(), never a class name)
  accessToken   encrypted_string   ← ciphertext + key id envelope (D-78)
  refreshToken  encrypted_string null
  expiresAt     timestamptz null
  scopes        jsonb           ← granted scopes as returned, not as requested
  providerAccountId  string
  providerDisplayName string null
  status        enum            ← connected | needs_reauth | revoked_by_user (D-80)
  linkedAt / updatedAt  timestamptz
  UNIQUE (user_id, provider)                       ← AC-1.5
  INDEX (status) WHERE status <> 'connected'       ← operator visibility, cheap
```

This is the `StreamingAccount` already sketched in `docs/architecture.md` §10; that sketch is updated
in this branch to name the real columns. `Playlist`, `PlaylistTrack` and `ProviderSetting` remain
later prompts.

### The OAuth round trip

```
client            backend                         Spotify
  │  start link ─────►│ generate state + PKCE, store in Redis (TTL)
  │  ◄─ auth URL ─────│
  │ ── open URL ──────────────────────────────────►│  user consents
  │                   │◄── redirect ?code&state ───│   (SPOTIFY_REDIRECT_URI, one per env)
  │                   │ validate+consume state, exchange code (+verifier), fetch /me
  │                   │ persist encrypted StreamingAccount, mint one-time result ref
  │  ◄─ redirect to web route │ setlistify:// deep link, carrying only the ref
  │  resolve ref (authenticated) ►│
  │  ◄─ linked account state ─────│
```

Web and native differ only in the final hop (`ClientPlatform` already distinguishes them for the
refresh cookie). One registered redirect URI per environment, per `docs/env-vars.md`.

### New environment variables

| Variable | Secret | Default | Purpose |
|---|---|---|---|
| `SPOTIFY_CLIENT_ID` | no | — | Already declared |
| `SPOTIFY_CLIENT_SECRET` | **yes** | — | Already declared |
| `SPOTIFY_REDIRECT_URI` | no | — | Already declared; one per environment (AC-1.9) |
| `TOKEN_ENCRYPTION_KEY` | **yes** | — | Already declared — now the **active** key |
| `TOKEN_ENCRYPTION_KEY_ID` | no | `v1` | **New** — id stamped into every new ciphertext (AC-6.3) |
| `TOKEN_ENCRYPTION_KEYS_RETIRED` | **yes** | empty | **New** — `id:base64key` pairs, comma-separated, still valid for decryption only (AC-6.4) |
| `SPOTIFY_API_BASE_URL` | no | `https://api.spotify.com/v1` | **New** — so fixtures and the live smoke test point elsewhere without code changes |
| `SPOTIFY_ACCOUNTS_BASE_URL` | no | `https://accounts.spotify.com` | **New** — same reason, for the OAuth endpoints |
| `STREAMING_HTTP_TIMEOUT` | no | `5` | **New** — provider-agnostic outbound timeout, seconds (AC-10.6) |
| `STREAMING_TOKEN_REFRESH_SKEW` | no | `60` | **New** — refresh this many seconds before expiry (AC-4.1) |
| `STREAMING_LINK_STATE_TTL` | no | `600` | **New** — pending-link lifetime, seconds (AC-8.2) |
| `STREAMING_LINK_RETURN_URL_WEB` | no | `http://localhost:8081/account` | **New** — web return route (AC-1.7) |
| `STREAMING_LINK_RETURN_URL_NATIVE` | no | `setlistify://account` | **New** — native deep link (AC-1.8) |

All new variables go into `docs/env-vars.md` **and** `backend/.env.example` in the same commit.

### Frontend shape

- `frontend/app/(app)/account.tsx` gains a **Connections** section (existing screen, not a new
  navigation area), listing linked accounts with status and a connect/reconnect/disconnect action.
- `frontend/lib/streaming/` holds the platform split: `linkAccount.web.ts` (full-page redirect) and
  `linkAccount.native.ts` (`expo-web-browser`'s `openAuthSessionAsync`), behind one shared signature
  — the same `.web`/`.native` pattern `DateField` already established (D-38).
- No OAuth library on the client. The client opens a URL the backend produced and resolves an opaque
  reference afterwards; it never sees a code, a verifier or a token (D-74).
- Types come from `frontend/api/schema.d.ts`, regenerated from the OpenAPI spec **before** any client
  code is written (`CLAUDE.md` — regenerate before wiring up). No request or response shape is
  hand-declared.

### Decisions

Numbered from **D-71**; `D-1`–`D-3` are project-wide (`docs/architecture.md`), `D-4`–`D-9` backend
skeleton, `D-10`–`D-17` frontend skeleton, `D-18`–`D-23` auth, `D-24`–`D-31` concert domain,
`D-32`–`D-41` concert tracker UI, `D-42`–`D-55` backoffice foundation, `D-56`–`D-70` setlist.fm.

**D-71 — The port is frozen at `docs/architecture.md` §4's nine methods; a provider that needs more
does not get a wider interface.**
The interface's value is entirely in its narrowness. The moment one adapter adds a method, every
consumer learns to ask "does this provider support X?", and provider knowledge is back in the
calling code wearing a different hat. If a future provider genuinely cannot express something
through the nine methods, the answer is a *capability* value object returned by the port, or the
feature does not exist for that provider — not an eleventh method. Cost accepted: the first genuinely
awkward provider will make this rule feel expensive, and paying it will still be cheaper than the
alternative.

**D-72 — Adapters are resolved by key through a tagged-service locator; no consumer names a class.**
The rule "one directory to add a provider" is only true if nothing enumerates providers. So
adapters carry a service tag, the locator maps `key()` → instance, and an unknown key is a typed
domain error rather than a class-not-found. This is also what makes AC-9.5's test-double proof
possible: the double is registered by existing tag, and *nothing* is edited to accept it. Rejected:
a match/switch on provider key, which is a leak in a different file.

**D-73 — Error mapping is the adapter's job; no provider status escapes.**
An HTTP 429 from Spotify and a quota rejection from YouTube mean different things
(`docs/external-apis.md`: YouTube's constraint is units/day, Spotify's is a rolling rate), and a
caller that branches on `429` will be wrong for the second provider. Adapters map to the taxonomy;
`ProviderUnavailable` is the catch-all so an unclassified failure is still a taxonomy value
(AC-10.5). The cost is a mapper per adapter — a small, testable class, and precisely the place a new
provider's quirks belong.

**D-74 — The backend owns the entire OAuth exchange; the client never holds a code, verifier or
token.**
Setlistify is a confidential client — it has a client secret — so the exchange must be server-side
regardless. PKCE is used *anyway* (AC-1.2) because it costs nothing and closes code interception on
the native leg, which is the platform where the redirect is least controllable. The consequence is
that the Expo app never handles provider credentials at all: it opens a URL and later reads state.
That also means the web client is never asked to store a provider token, so `docs/architecture.md`
§11's storage rules are simply not in play for provider tokens on the client.

**D-75 — One registered redirect URI per environment; the platform split happens after the exchange.**
The obvious design — one redirect URI per platform — multiplies registrations across two Spotify apps
(dev and prod) and, later, per provider, and every one of them is a place a production key can be
pointed at a development host. Instead: the provider always redirects to the backend
(`SPOTIFY_REDIRECT_URI`), the backend completes the exchange, then redirects onward to a web route or
a `setlistify://` deep link chosen from `ClientPlatform` (recorded in the pending link at start time,
AC-8.1). The onward hop carries a one-time opaque reference and nothing else, so no secret ever
appears in a URL a browser, a proxy or an OS log can see. The prompt flags this plumbing as the
fiddliest part of the feature — this decision is what keeps the fiddliness in one place.

**D-76 — `state` is a server-side record, not a signed blob.**
A signed token carrying the user id would work and would be stateless, but it cannot be made
single-use without server state anyway (AC-8.2's replay rejection), and it would put a
forever-valid-until-expiry credential in a URL. A Redis record with a short TTL, deleted on first
use, gives single-use and revocation for free, and Redis is already a hard dependency with a
fail-closed posture (`RateLimiterGuard`). Redis unavailable therefore means linking is unavailable —
correct, and vastly better than linking that skips replay protection.

**D-77 — `StreamingAccount` copies `Concert`'s ownership shape exactly, 404 and all.**
`CLAUDE.md` states that every later user-scoped resource copies D-27's pattern, and this is the first
one to do so. `StreamingAccountOwnerExtension` filters every query to the current owner *before* a
voter runs; `StreamingAccountVoter` is the second gate for any path that loads the entity outside
that query. `ConcertOwnerExtension` is not modified, not generalised, not made role-aware — the
duplication is deliberate and cheap, and D-47's separate-channel rule for the backoffice holds here
identically. A 403 would confirm the id exists; for a resource whose existence reveals that a
specific person uses Spotify, that leak is worth closing even though the id is a UUID.

**D-78 — Encryption is a Doctrine type with a key-id envelope, not a service call.**
`docs/env-vars.md` already requires both properties ("applied through a custom Doctrine type so
encryption is not something a developer can forget to call" and "store a key id alongside each
ciphertext"). This branch is where they become real. The envelope is
`v<version>:<keyId>:<base64(nonce‖ciphertext)>`, versioned so a future algorithm change is a
detectable state rather than an ambiguous parse. `TOKEN_ENCRYPTION_KEY` + `TOKEN_ENCRYPTION_KEY_ID`
is the active pair; `TOKEN_ENCRYPTION_KEYS_RETIRED` holds decrypt-only predecessors. Rotation is
therefore: add the old key to the retired set, set a new active pair, deploy — no downtime, no
migration, and re-encryption becomes an optional background tidy rather than an emergency. Unknown
key id fails loudly (AC-6.5): a silent null here would look exactly like "user never linked an
account" and would be discovered as data loss.

**D-79 — Refresh is proactive, centralised, and single-flight; callers never refresh.**
Reactive refresh (call, catch 401, refresh, retry) means every caller has to remember the retry, and
prompt 14's pipeline makes dozens of calls per generation — dozens of chances to forget. So
`StreamingTokenManager` is the only thing that refreshes, it refreshes *before* expiry
(`STREAMING_TOKEN_REFRESH_SKEW`), and it holds a per-account lock so N concurrent workers cause one
refresh, not N (AC-4.3). The last property matters more than it looks: providers commonly invalidate
a refresh token on use, so a refresh race is not merely wasteful, it is a way to break a link. This
mirrors the client-side single-flight guard prompt 04 already required for the app's own JWTs.

**D-80 — An unrecoverable grant failure sets `needs_reauth`; the row survives.**
Deleting the account on a failed refresh throws away the display name, the link date and — more
importantly — the UI's knowledge that this user *had* a connection, which is what makes a "Reconnect"
affordance possible instead of a blank slate. So the tokens are cleared and the status changes, and
relinking updates the same row (AC-1.5). The distinction between unrecoverable and transient
(AC-5.2) is the whole load-bearing part: a network blip must never demote a healthy account, so only
an explicit invalid-grant class of response flips the status.

**D-81 — Unlink deletes locally and revokes only where a provider supports it — and Spotify does not.**
The prompt's acceptance criterion says "unlinking revokes and deletes stored tokens". Deletion is
fully in our control and is authoritative. Revocation is not: **Spotify's Web API exposes no token
revocation endpoint**; access is removed by the user from their Spotify account's Apps page. Rather
than pretend, the port allows an adapter to declare revocation unsupported, the deletion happens
regardless, and the UI tells the user the honest extra step with a link (AC-3.3). Writing this down
matters because the alternative — a silently no-op "revoke" — would read as a security guarantee the
product does not have.

**D-82 — The symbol ban is enforced by a test over `backend/src/`, and configuration is confined
rather than banned.**
`CLAUDE.md`'s rule is about PHP symbols; environment variable names like `SPOTIFY_CLIENT_ID` are not
symbols and cannot be renamed away (they are dictated by `docs/env-vars.md` and by the operator's
mental model). The compromise: the test scans `backend/src/` only, and all Spotify parameter binding
lives in one dedicated config file, so the non-adapter footprint is exactly one reviewable location
(AC-9.6). The prompt is right that this test is worth more than it looks and should be written
**first** — it is what makes prompt 18 a directory instead of a rewrite, and it only works if it
exists before the code it constrains.

**D-83 — The confidence score is explicitly provisional, and lives in one method.**
Prompt 12 is a spike whose entire purpose is designing the ranking. Building a real one here would
either duplicate that work or prejudge it. So `searchTrack()` returns candidates with a documented,
naive normalized score, isolated in one method with a docblock naming prompt 12 as its owner
(AC-11.2). The risk this accepts is that something downstream starts trusting the score before it is
good — mitigated by there being no downstream consumer until prompt 14, which lands after 12.

**D-84 — The backoffice observes linked accounts and can unlink; it can never see or set a token.**
Consistent with prompt 08's read-mostly posture and `CLAUDE.md`'s "the backoffice edits behaviour,
never credentials". Support needs to answer "is this user's Spotify connected?" and "clear their
broken link so they can reconnect" — both satisfiable without any token visibility. Fields are
enumerated through the abstract `configureFields` (D-46) so a future column cannot appear in the
admin by default, and the one write is audited (D-43).

**D-85 — Fixtures are recorded once by hand; exactly one live smoke test, never in CI.**
`docs/architecture.md` D-2 forbids CI from calling external APIs, and here there is a second reason:
the 5-user cap means CI would be consuming one of five human-sized slots. Fixtures are captured
deliberately, scrubbed (AC-12.4), and committed; a `@group live` test run manually before a release
catches the day Spotify's shapes move. Accepted cost: fixture drift is invisible to CI, exactly as it
is for setlist.fm (D-70).

**D-86 — Provider availability is consumed through a seam now, even though its answer is constant.**
Prompt 11 owns `ProviderSetting` and `ProviderRegistry`, and this branch must not build them. But
`CLAUDE.md` requires that anything offering a provider to a user reads provider state at runtime, and
retrofitting that read into every call site later is exactly the leak this feature exists to prevent.
So the call sites ask a `ProviderAvailability` interface today; its implementation in this branch
answers "every registered adapter is available". Prompt 11 replaces one class and changes no caller.

**D-87 — Playlists created by the port are private by default.**
`playlist-modify-public` is a strictly larger grant, and nothing in the product's current scope needs
a public playlist. Requesting the minimum keeps the consent screen honest and limits what a token
leak could do. If public playlists become a feature, it is an additive scope change with a re-auth
prompt — which is a product decision, made deliberately, not a default inherited from this branch.

**D-88 — Scopes are declared in one place inside the adapter and requested at their minimum.**
Scope lists have a way of accreting "while we're here" entries that later require every user to
re-consent. One constant, inside `Spotify/`, holding exactly what AC-1.3 lists. The granted scopes
are stored as *returned* by the provider, not as requested (data model), because those are the two
things that drift.

### Suggested implementation order

1. **The architecture test first** (AC-9.4), against an empty `Service/Streaming/`. It fails on the
   first leak rather than after the leak has consumers (D-82).
2. Port + value objects + error taxonomy + locator (US-9, US-10), with the test-double adapter and
   the AC-9.5 proof written alongside — before any real adapter exists to bias the shape.
3. `TokenCipher` + `EncryptedStringType`, with the rotation test (AC-6.4) and the raw-column test
   (AC-6.2). Encryption exists before there is anything to store.
4. `StreamingAccount` entity + migration + owner extension + voter, with the cross-owner 404 test
   (AC-2.4, AC-3.5).
5. Spotify adapter: HTTP client, OAuth exchange/refresh, error mapper, `/me` identity — driven
   entirely by fixtures (capture them at the start of this step, deliberately).
6. `PendingLinkStore` + `LinkFlowService`: state, PKCE, single-use, rate limiting, platform-aware
   return (US-1, US-8).
7. `StreamingTokenManager`: proactive refresh, single-flight lock, status transitions (US-4, US-5).
8. API operations (start, callback, resolve reference, list, unlink) + serialization allowlist test
   (AC-7.1) + log redaction (AC-7.2). Regenerate `frontend/api/schema.d.ts`.
9. Search / create playlist / add tracks through the port (US-11).
10. Frontend: connections section, platform-split link flow, reconnect path, empty state (US-1..US-3,
    US-5). Verify on web, iOS and Android against the running stack.
11. Backoffice panel + audited unlink (US-7, D-84).
12. Documentation updates and `/doc-check` before the PR.

## Out of Scope

| Not in this feature | Why / where it goes |
|---|---|
| **Which providers are enabled, and playback mode** — `ProviderSetting`, `ProviderRegistry`, `GET /api/config/providers` | Prompt 11. This branch assumes Spotify is available and consumes the D-86 seam so prompt 11 is additive |
| **Track matching quality** — normalization, ranking, live/cover preference, confidence design | Prompt 12 (spike). This branch returns candidates with a naive, explicitly provisional score (D-83) |
| **Playlist generation** — the pipeline, Messenger jobs, per-song outcome reporting, `Playlist`/`PlaylistTrack` entities | Prompt 14 (fast mode) and 17 (normal mode) |
| **YouTube, Apple Music, or any second real adapter** | Prompt 18 (YouTube). The test double (AC-9.5) proves the seam without shipping a second provider |
| **The concert page playback surface** — embeds, deep-link handoff | Prompt 19. `playlistEmbedUrl()`/`playlistDeepLink()` are implemented and tested here but consumed there (AC-9.7) |
| **Public playlists**, collaborative playlists, playlist artwork | D-87. Additive scope changes, made as product decisions |
| **Reading a user's existing Spotify library** — saved tracks, top artists, taste data | Not needed, and `docs/external-apis.md` restricts what Spotify data may be used for. Requesting scopes we do not need is the opposite of AC-1.3 |
| **Social login with Spotify** (using it to sign in to Setlistify) | Explicitly not this. Linking is authorization for an already-authenticated user; conflating the two would make account recovery depend on a 5-user-capped provider. Prompt 04 already deferred social login |
| **Multiple accounts per provider per user** | `(user, provider)` is unique (AC-1.5). Nothing in the product needs two Spotify accounts, and it would make "which one?" a question every later feature has to ask |
| **Automatic re-encryption of stored tokens after a key rotation** | D-78 makes rotation work without it. A background re-encryption command is a small, separate change, best written when a rotation is actually scheduled |
| **Per-user quota or entitlement on provider calls** | Prompt 22 (entitlement and quota seam) |
| **Applying for Spotify Extended Quota Mode** | `docs/external-apis.md` treats it as unavailable (250k MAU). Not a code dependency, not a blocker, not attempted |
| **Monetization-driven `playbackMode` changes** | Prompt 11 owns the flag; prompt 23 owns the monetization question |

## Dependencies

**Must be true before implementation begins**

| Dependency | Provides | Status |
|---|---|---|
| **Prompt 04 merged — auth and accounts** | The `User` entity a `StreamingAccount` hangs off; JWT-authenticated operations; `UserIdProvider` for the current owner; `ClientPlatform` for the web/native split (D-75); `RateLimiterGuard` with its fail-closed posture (AC-8.6); the Monolog redaction processor this branch extends (AC-7.2) | **Met** |
| **Prompt 08 merged — backoffice foundation** | `AbstractAdminCrudController` with abstract `configureFields` (D-46) so the linked-account panel cannot leak a token field by default; `AuditLogger` for the one admin write; the 2FA-gated admin firewall; D-47's separate-channel rule that this branch's owner extension relies on | **Met** |
| Prompt 05's ownership pattern (`ConcertOwnerExtension`, `ConcertVoter`) | The shape `StreamingAccountOwnerExtension` copies (D-77) | **Met** |
| Prompt 02's design tokens and components (`docs/design/canvas/`) | The connections section's visual language, 44×44 targets, status treatment (AC-2.5) | **Met** |
| **A Spotify developer application, with the developer's test accounts allowlisted** | Any manual verification, fixture capture and the live smoke test. **Development Mode allows 5 users** — every account used for testing must be added in the dashboard *before* work starts | **To confirm — blocking for steps 5 and 10** |
| **Two separate Spotify app registrations (dev and prod), different redirect URIs** | `docs/env-vars.md`'s credential separation; `docs/architecture.md` §11's "a leaked development key cannot reach production data" | **To confirm** |
| `TOKEN_ENCRYPTION_KEY` generated for each environment | US-6. Already declared as a variable; a real value must exist in dev before step 3 | **To confirm** |
| `symfony/lock` installed | Single-flight refresh (AC-4.3) | **Met** (`composer.json`) |
| Redis reachable and shared across processes | Pending link state (D-76), rate limiting | **Met** (compose `redis`, healthchecked) |
| `libsodium` available in the PHP image | The Doctrine encryption type. Already relied on by prompt 08's TOTP secret encryption | **Met** |
| `expo-web-browser` available in the Expo app | Native auth session (AC-1.8) | **To verify** — add as a dependency if absent |
| Deep link scheme `setlistify://` registered | Native return leg | **Met** (`frontend/app.json`) |

**Depended on by**

- **Prompt 11 (backoffice provider configuration)** — replaces D-86's availability implementation and
  adds `ProviderSetting`; changes no call site if this branch holds its seam.
- **Prompt 12 (song matching spike)** — consumes `SongQuery`/`TrackCandidate` and replaces D-83's
  naive score.
- **Prompt 14 / 17 (playlist generation)** — the only reason the port exists; consumes
  `createPlaylist()`/`addTracks()` and the D-79 token manager.
- **Prompt 18 (YouTube adapter)** — the direct beneficiary of D-71, D-72 and D-82. If prompt 18 needs
  to change anything outside its own directory, this feature failed.
- **Prompt 19 (concert page player embed)** — consumes `playlistEmbedUrl()`/`playlistDeepLink()`.
- **Prompt 22 (entitlement and quota seam)** — provider calls are the metered resource.

**Assumptions** *(labelled as assumptions, not verified facts)*

- Spotify's refresh response usually omits a new refresh token and the existing one remains valid
  (AC-4.4 handles both cases, so this assumption being wrong changes nothing).
- Spotify has no token revocation endpoint (D-81). This is stated from current documentation and
  should be re-confirmed during step 5; if one exists, AC-3.2's best-effort path already accommodates
  it and only AC-3.3's copy changes.
- The 5-user Development Mode cap applies to authenticated users, not to app installs, so the
  developer's own devices across platforms do not each consume a slot.
- `expo-web-browser`'s auth session returns reliably to the registered scheme on both iOS and
  Android in this Expo version. This is the single most likely thing to cost unplanned time (R-4).
- 60 seconds is a sufficient refresh skew for the deployment's clock accuracy. It is env-configurable
  precisely because this is a guess.

## Risks & Open Questions

| # | Risk | Impact | Mitigation / decision |
|---|---|---|---|
| R-1 | **Provider logic leaks outside the adapter**, quietly, over the next eight prompts | Existential for the roadmap: prompt 18 becomes a rewrite and the product's only path past the 5-user cap gets expensive | Structural, not procedural, and **written first**: the AC-9.4 architecture test runs in the default suite, and D-71/D-72 remove the two usual leak routes (a widened interface, a class reference). AC-9.5's test double proves the property rather than asserting it |
| R-2 | **The 5-user Development Mode cap is hit immediately**, producing confusing 403s from accounts nobody remembered to allowlist | High for developer time, zero for design — it is a known permanent condition | Allowlist every test account in the Spotify dashboard *before* step 5 (listed as a blocking dependency). The adapter maps the resulting failure to a taxonomy value with a message naming the cap, so the next person recognises it in one read |
| R-3 | **Spotify is never a launch provider** — Extended Quota Mode needs 250k MAU | Existential if the roadmap assumes otherwise | Already the stated position (`docs/external-apis.md`). This feature's *only* job with respect to that risk is making provider #2 cheap, which is what R-1's mitigations buy |
| R-4 | **OAuth redirects across web, iOS and Android are the fiddliest part of this work** | Medium — schedule risk, not design risk | D-75 concentrates all of it in one hop and one env-var pair, so debugging is localised. Budget time explicitly for step 10; verify on a real device, not only a simulator, before calling AC-1.6 met |
| R-5 | **A refresh race breaks a link** — two workers refresh at once and one token wins | High and intermittent, which is the worst combination | D-79's per-account lock, with AC-4.3 testing the concurrent case explicitly rather than trusting it |
| R-6 | **The encryption key is lost or rotated without keeping the predecessor** | High — every linked account becomes unusable and every user must relink | `TOKEN_ENCRYPTION_KEYS_RETIRED` makes keeping predecessors the default path (D-78); AC-6.5 fails loudly rather than silently emptying tokens; `docs/env-vars.md`'s leak runbook already prescribes re-linking as the recovery |
| R-7 | **A token reaches a log** via a future call site logging a request context carelessly | High — a plaintext credential in an aggregator | The redaction processor is central and channel-wide (AC-7.2), the HTTP client never logs bodies or auth headers (AC-7.3), and both are asserted by test rather than reviewed by eye |
| R-8 | **A future field leaks a token into an API response or the admin** | High | Allowlist-based serialization test (AC-7.1) and D-46's abstract `configureFields` (AC-7.4): in both places, adding a field without declaring it makes it invisible, not exposed |
| R-9 | **`ProviderSetting` arrives in prompt 11 and forces call-site changes** | Medium — would falsify the "one directory" claim on its first real test | D-86: the availability seam is consumed from day one with a constant implementation |
| R-10 | **The naive confidence score gets trusted** before prompt 12 designs a real one | Medium — silently bad playlists later | D-83 isolates it in one documented method, and no consumer exists until prompt 14, which lands after the spike |
| R-11 | **Fixture drift** — Spotify changes response shapes and the offline suite stays green | Medium | The `@group live` smoke test (AC-12.3), run manually before releases. Accepted: CI cannot catch this and D-2 says it must not try |
| R-12 | **Scope creep into matching or generation** — "the port can search, let's just build the playlist" | Medium | The Out of Scope table is binding. The branch contains no `Playlist` entity and no pipeline; that is reviewable in the diff |
| R-13 | **A user unlinks and assumes access is fully revoked**, when Spotify still lists the app | Low, but a trust issue if discovered later | Stated honestly in the UI (AC-3.3) rather than papered over — the reason D-81 exists as a written decision |

**Open questions — for the user to resolve on approval**

1. **Unlink copy and the Spotify account-settings link (AC-3.3).** Confirm that telling users plainly
   "we deleted our copy; remove Setlistify in your Spotify account settings to revoke it there" is
   the wanted tone, versus a quieter message. Recommendation: the plain version.
2. **Should the backoffice be able to unlink on a user's behalf (D-84)?** It is one audited write and
   is genuinely useful for support, but it is also an admin acting on a user's third-party
   connection. Recommendation: include it, audited.
3. **Refresh skew (60s) and pending-link TTL (600s)** — sensible starting points, or different
   defaults? Both are env-configurable either way.
4. **Should a `needs_reauth` account be surfaced anywhere other than the account screen** (e.g. a
   banner)? Recommendation: not in this branch — it becomes meaningful when generation exists
   (prompt 14) and can be added there with real context.

## Documentation to update in this branch

Per `CLAUDE.md`'s mandatory documentation check (run `/doc-check` before committing):

- **`docs/env-vars.md`** *and* **`backend/.env.example`** — the nine new variables listed above, plus
  a note on the rotation procedure (`TOKEN_ENCRYPTION_KEY_ID` + `TOKEN_ENCRYPTION_KEYS_RETIRED`) in
  the existing "Encrypting users' provider tokens" section. Both files or neither.
- **`docs/architecture.md`** — §4 updated from a sketch to the shipped port (naming the locator, the
  value objects and the error taxonomy); §10's data model sketch updated with `StreamingAccount`'s
  real columns; §11 extended with the linking flow's state/PKCE posture and the key-rotation model.
- **`docs/external-apis.md`** §Spotify — record the revocation finding (D-81) and the confirmed
  minimal scope set, in the change log.
- **The OpenAPI spec** — regenerated from the API Platform resources; no endpoint is listed in any
  README (`CLAUDE.md`).
- **`frontend/api/schema.d.ts`** — regenerated before the client work, not after.
- **`frontend/README.md`** — only if `expo-web-browser` is added as a new dependency or the route
  structure changes.
- **`backend/src/Service/Streaming/README.md`** and **`backend/src/Service/Provider/README.md`** —
  updated from "out of scope for this feature" to what is now there.
