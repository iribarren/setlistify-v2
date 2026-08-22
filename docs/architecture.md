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

**D-32 — The concert list is one scroll with Upcoming/Past sections, not two tabs**
(`docs/specs/2026-08-21-concert-tracker-ui.md`, `frontend/app/(app)/concerts/index.tsx`). Sections
keep past concerts visible without a deliberate act, implemented as two independent
`useConcertsSection` paginated queries (`status=upcoming`/`status=past`) so a later move to tabs is
a layout change, not a data-layer rewrite.

**D-33 — Optimistic concert creation is reconciled from the response, never merged with it.** The
optimistic card (`lib/concerts/queries.ts`'s `useCreateConcert`) carries a client-generated temp id
and is marked pending; on `201` it is discarded and replaced wholesale by the server's
`Concert.ConcertOutput` — never merged — because band dedup (D-25) may return a different `Band` id
and a differently-cased name than the client sent.

**D-34 — All date/time platform branching lives in one `DateField` component**
(`frontend/components/DateField.native.tsx` / `DateField.web.tsx`). Web uses the browser's native
`<input type="date">`; native is plain `YYYY-MM-DD` text entry pending a vetted cross-platform date
picker dependency clearing D-15's web-support gate. No screen imports `Platform` directly.

**D-35 — The client sends the device's IANA timezone and renders a concert in its own zone, never
the viewer's.** `Intl.DateTimeFormat().resolvedOptions().timeZone` supplies `timezone` on create;
`lib/concerts/mapping.ts`'s `formatConcertDate` formats from the concert's own `date` + `timezone`,
anchored at local noon so no `timeZone` can push the result to an adjacent day (D-24).

**D-36 — Client-side concert-form validation mirrors D-31's bounds but is advisory only; the
server is authoritative.** `lib/concerts/validation.ts` blocks an obviously-invalid submit and shows
inline errors, but every response is rendered as-is — a server violation is never suppressed or
overridden by what the client believed was valid. RFC 7807 violations (`lib/concerts/violations.ts`)
map `propertyPath` (including indexed `lineup[n].*` paths) onto form fields; an unrecognised path
surfaces in a form-level summary rather than being dropped.

**D-37 — Reads fall back to the TanStack Query cache offline; writes fail rather than queue.** No
offline write queue or background sync for concerts — a queued write needs conflict handling, a
durable outbox and a duplicate-create story, each its own spec. A write attempted offline fails fast
with the user's input intact.

**D-38 — Money and dates are converted in exactly one place**, `frontend/lib/concerts/mapping.ts` —
decimal ⇄ minor units (D-28) and `Intl`-based formatting. No component does arithmetic on a price or
parses a date string itself.

**D-39 — Phone vs. desktop concert-shell layout is a width breakpoint in one layout file**
(`frontend/app/(app)/_layout.tsx`, `frontend/components/nav/breakpoint.ts`), not a `Platform.OS`
fork, continuing spec 03's D-15/AC-1.8. Simplified to a single 900px breakpoint (bottom tab bar below
it, persistent sidebar at or above it) rather than the design canvas's extra collapsed-rail and
tablet-drawer bands — a recorded simplification, not a missed requirement.

**D-40 — Concert delete is permanent and the confirmation says so.** The API hard-deletes (spec 05,
AC-6.5) — `DeleteConfirmation` names the concert and uses destructive styling; there is no undo, no
trash.

**D-41 — Concert list infinite scroll reads the Hydra `view.next` link's own `page` number as the
next cursor**, rather than computing page numbers client-side, so the client never disagrees with
the server about how many pages exist. Page size 20 (`lib/concerts/queries.ts`).

**D-42 — `/admin` keeps the default path and is IP-restricted in production**
(`docs/specs/2026-08-21-backoffice-foundation.md`). Path obscurity is not security, so
`ADMIN_PATH_PREFIX` stays `/admin`; the real question is whether the door is publicly routable at
all. `ADMIN_IP_ALLOWLIST` empty means unrestricted (dev/CI); non-empty means
`App\EventSubscriber\AdminIpAllowlistListener` rejects non-matching sources with a **404** (never
403 — an outsider must not learn the prefix exists) before authentication runs, reading the client
IP through Symfony's trusted-proxy-aware `getClientIp()`. A startup check logs `error` if `prod` runs
with an empty allowlist.

**D-43 — Audit records store an actor reference and personal-data digests, never plaintext or FKs.**
`AuditLogEntry.actorId` is a plain integer with **no foreign key** to `users`; `actorLabel` and any
`oldValue`/`newValue` for a personal-data field are a keyed HMAC digest (`App\Service\Admin\
AuditLogger::digest()`), never the plaintext. A boolean flip (`isActive`) is stored literally. This
lets the trail survive the deletion of the user it describes (GDPR erasure) without resurrecting
their personal data — at the cost of an entry that cannot be read back as "who exactly" once the
actor's own account is gone.

**D-44 — Suspension reuses `User::$isActive`; no new state field.** `LoginProcessor` already refuses
inactive users, so suspension works through an already-tested path. Revoking every refresh token is
part of the action (`RefreshTokenRepository::revokeAllForUser()`), not an afterthought — otherwise
suspension is cosmetic for up to the refresh token's lifetime.

**D-45 — Erasure is a hard delete with an explicit cascade, executed by one service**
(`App\Service\Admin\UserEraser`, one DB transaction). `Concert`, `RefreshToken`,
`PasswordResetToken` and `EmailVerificationToken` all declare `onDelete: 'CASCADE'` on their
`user`/`owner` foreign key already, so deleting the `users` row cascades at the database level;
`Band` and `Venue` carry no such foreign key at all, so they survive by construction. The audit entry
is written in the same transaction and (D-43) holds no FK to the row being deleted.

**D-46 — Field lists are allowlists, structurally enforced.** EasyAdmin's own
`AbstractCrudController::configureFields()` has a non-abstract default that exposes every entity
property — the mechanism a hash or token ends up on a screen. **Deviation from the original plan**:
PHP does not allow re-declaring an inherited *concrete* method as `abstract`, so
`App\Controller\Admin\AbstractAdminCrudController` instead overrides it with an implementation that
unconditionally throws; every concrete controller must override it again to render anything, and a
test asserts the throw directly. `configureActions()` also disables `NEW`/`EDIT`/`DELETE`/
`BATCH_DELETE` by default, so a new controller inherits read-only rather than write access by
omission.

**D-47 — The admin reads across owners through Doctrine, never by weakening the API's gate.**
`App\Security\ConcertOwnerExtension` is not modified, not made role-aware, not bypassed with a
`ROLE_ADMIN` branch. EasyAdmin's CRUD controllers query Doctrine directly; every one of those reads
is an operator action inside an audited, 2FA-gated session — a separate channel from the public API,
not a hole in it.

**D-48 — Firewall order is `dev` → `admin` → `api` → `main`, and the prefix is a build-time value.**
Firewall matching is first-match-wins, so `admin` precedes `api` in `security.yaml`. Symfony compiles
firewall patterns and route paths into the container, so `ADMIN_PATH_PREFIX` is a **build-time**
setting — changing it needs a cache clear/rebuild, not just an env change.

**D-49 — 2FA enrollment is forced on first login and recovery is console-only.** An admin account
with no TOTP secret can reach only the enrollment route
(`App\Controller\Admin\TwoFactorEnrollmentController`) — enforced primarily by replacing scheb/2fa's
`authentication_required_handler` (`App\Security\Admin\ForceEnrollmentAuthenticationRequiredHandler`;
scheb's own `TwoFactorAccessListener` runs *inside* the firewall's listener stack, too early for an
external `kernel.request` subscriber to override its redirect target — this was the actual
implementation surprise, documented in the handler's own docblock). Lost-device recovery is
`bin/console app:admin:2fa:reset` — the same shell-access bar as provisioning; there is no web-based
recovery flow.

**D-50 — Admin login errors are honest; enumeration is not a threat here.** The API's uniform-401
posture (auth spec US-9) doesn't apply: there is exactly one admin account, its address is known to
the one person who should be logging in, and a lockout message states the lockout and its remaining
duration (`App\Security\Admin\AdminUserChecker`).

**D-51 — Masking is one field type, used everywhere an email is rendered**
(`App\Field\MaskedEmailField`, backed by `App\Service\Admin\EmailMasker`). **A second leak path
turned up during implementation and is now covered by the same rule**: EasyAdmin's stock
`crud/field/text.html.twig` renders `title="{{ field.value }}"` — the field's *raw*, pre-formatValue
value — as a hover tooltip, regardless of `formatValue()`; `MaskedEmailField` uses a custom template
that omits it. EasyAdmin's own dashboard user-menu widget was a third instance (it calls
`getUserIdentifier()` on the logged-in user directly) — `DashboardController::configureUserMenu()`
overrides it to mask the operator's own email too.

**D-52 — No API change, and a test that keeps it that way.** This feature adds no endpoint, changes
no schema, regenerates no client types. `AdminOpenApiTest` asserts no path in the generated OpenAPI
document starts with the admin prefix and no schema references `AuditLogEntry`.

**D-53 — Dashboard counts are computed per request, uncached.** Three `COUNT` queries cost less than
a cache-invalidation story; revisit if a count gets slow.

**D-54 — The admin session is a separate cookie with a short idle timeout, stored in Redis.** Name
`admin_session`, path scoped to the admin prefix — distinct from the API's `refresh_token` cookie
(`/api`), so neither is ever sent where the other is expected. 30-minute idle timeout via
`gc_maxlifetime` (Redis-backed, so it holds across app instances); an 8-hour **absolute** lifetime is
enforced separately by `App\EventSubscriber\AdminSessionLifetimeSubscriber`, since Symfony's session
component has no native concept of "session started at".

**D-55 — The linked-provider count is omitted from the users list, not stubbed as zero.**
`StreamingAccount` doesn't exist until prompt 10; a column that always reads `0` would teach the
operator to ignore it.

**D-56 — MBID is the band's identity everywhere; the typed name is only ever a lookup hint**
(`docs/specs/2026-08-22-setlistfm-integration.md`). Once a `Band` carries an MBID, no code path may
re-derive identity from `normalizedName`; every setlist.fm call is by MBID. `normalizedName` (D-25)
keeps its job of deduplicating rows *before* setlist.fm has been consulted, and nothing more. A
partial unique index on `bands.setlistfm_mbid` (`WHERE setlistfm_mbid IS NOT NULL`) means the
database, not a service, guarantees one row per real band once resolved — a collision fails loudly
(operator-correctable, AC-11.5) rather than silently duplicating.

**D-57 — The disambiguation choice is stored on the shared `Band`, not per user.** `Band` is global
(D-25), so the choice must be too, otherwise every user pays the same question and the cache
fragments per user. First resolver wins; a wrong choice is visible and correctable in one place
(the backoffice's audited MBID-correction action, AC-11.5) — the same property that makes a mistake
shared makes the fix shared.

**D-58 — `SetlistGateway` is the only door; the HTTP client is not injectable elsewhere.**
`App\Service\Setlist\SetlistFmClient` is consumed solely by `SetlistCache`; nothing outside
`App\Service\Setlist\` may reference it, enforced by a static source scan
(`SetlistGatewayIsOnlyDoorTest`) rather than a container `has()`/`get()` check — the test
environment's `framework.test: true` makes every service publicly retrievable, which would make a
container-based check unable to distinguish the real production access boundary. The setlist.fm
analogue of the streaming port rule (§4): one seam, no side doors.

**D-59 — Two freshness classes, decided by the data's nature, not a global TTL.** Immutable data (a
specific past setlist's detail; a page of a band's setlist index whose entries are all in the past)
is stored with `staleAfter = NULL` and is never re-fetched. Volatile data (artist search results,
the first page of a band's index, which can gain entries) carries a `staleAfter`. The Redis tier
keeps `SETLISTFM_CACHE_TTL` for everything, since its only job is absorbing repeats within a
session.

**D-60 — Setlists are stored twice, on purpose: verbatim JSONB *and* relational rows.** The JSONB
payload (`setlist_cache.payload`) is the receipt — what setlist.fm actually returned — so a later
change to song-parsing logic is a re-derivation, not a re-fetch. The relational `Setlist`/`Song`
rows exist because matching, ordering and counting songs are queries; doing them through JSONB
operators would push provider-agnostic logic into PostgreSQL syntax.

**D-61 — Rate limit and daily budget are one gate, in Redis, fail-closed.** `SetlistFmBudget`
exposes a single `acquire()` consuming a per-second token bucket and a UTC-calendar-day counter
together, so no new call site can forget to consume one or the other in the right order. Both live
in Redis so the limit is application-wide, not per process. Redis unavailable means **no outbound
call** — a limiter that fails open is not a limiter (same posture as
`App\Service\Security\RateLimiterGuard`).

**D-62 — A web request never queues on the rate limiter.** With a 2/s global bucket, ten
simultaneous users would mean a five-second wait for the last one. The wait is bounded
(`SETLISTFM_TOKEN_WAIT`, default 1s) and expiry degrades to cache with `rate_limited`. The nightly
refresh job, not user-facing, is the one caller allowed to wait longer.

**D-63 — Degradation is a first-class field, not an HTTP status.** Every setlist-bearing response
carries `{source, fetchedAt, stale, reason}` (§5). Using status codes instead (503 for exhausted,
204 for nothing) would make the client's error path carry product meaning and make a perfectly good
cached answer look like a failure. 200 + an explicit reason lets the client render "showing setlists
from yesterday — fresh data available tomorrow at 00:00 UTC" without inventing the vocabulary
itself.

**D-64 — A shared circuit breaker, because retries spend real budget.** Every retry attempt that
reaches the network consumes one of 1,440 requests, so retries are capped (2), jittered,
`Retry-After` is honoured, and after 5 consecutive transient failures a Redis-shared breaker opens
for a cooldown during which zero calls are attempted. Without the shared state, N processes would
each discover an outage independently and spend N× the requests learning the same thing.

**D-65 — Freshness is a nightly, prioritized, budget-capped job — never an on-demand check.** The
refresh policy: one scheduled run per night (`app:setlist:refresh`), over bands attached to concerts
that are upcoming or ended in the last 7 days, nearest-first, spending at most
`SETLISTFM_REFRESH_BUDGET_SHARE` of the day's budget. On-demand per-user checks were rejected
outright: they scale with traffic (exactly what the budget cannot absorb) and their cost is paid in
user-visible latency. Trade-off accepted: a setlist published this morning may not appear until
tomorrow.

**D-66 — Setlist data is shared reference data, not a user-scoped resource.** Setlists, songs and
bands are facts about the world, identical for everyone. The 404-not-403 ownership gate (D-27) does
not apply and is not extended to these resources — they are authenticated (so the budget can't be
drained anonymously) but not owner-filtered. No `ConcertOwnerExtension`-shaped extension exists for
them.

**D-67 — The backoffice gets read-only views plus exactly two audited writes.** Cache-health,
budget and cache-entry views are read-only (consistent with D-46). The two exceptions — correcting a
band's MBID and clearing a band's cached setlist associations (AC-11.5) — are both routed through
`AuditLogger`. Notably absent: a "refresh this band now" button — a one-click budget spend on the
most dangerous resource in the product, and the nightly job already covers the need.

**D-68 — Cache and budget metrics live in Redis, not in a table.** Hit/miss counters are per-day
Redis counters with a 7-day expiry (for the dashboard's trailing-week view) — operational telemetry,
not domain data. Writing a row per cache read would make the cache slower than the thing it is
caching, and the numbers are worthless a week later.

**D-69 — The budget ceiling is configuration, not a constant.** `SETLISTFM_DAILY_BUDGET` and
`SETLISTFM_RATE_PER_SECOND` are env vars; no literal `1440` or `2` appears anywhere outside the
default declaration. Raising them is valid only after setlist.fm grants a higher tier
(`docs/external-apis.md`) — an operational action, not a code dependency.

**D-70 — Fixtures are recorded from real responses, committed, and are the fidelity ceiling of the
suite.** CI never calls setlist.fm (D-2), so `tests/Fixtures/setlistfm/` *is* the fidelity of the
tests. A single `@group live` smoke test exists to catch the day setlist.fm's shape changes
underneath them, run manually before a release — a scheduled live test would itself be a scheduled
budget spend.

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
every provider difference dies behind it. Shipped in
`docs/specs/2026-08-22-streaming-port-and-account-linking.md` (D-71–D-88); `App\Service\Streaming\
Spotify\` is the reference adapter.

```php
interface StreamingProviderInterface
{
    public function key(): string;                 // 'spotify' | 'youtube' | 'apple'

    // OAuth
    public function authorizationUrl(string $state, string $redirectUri, ?string $codeChallenge = null): string;
    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier = null): ProviderTokens;
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

Frozen at exactly these nine methods (D-71) — `authorizationUrl()`/`exchangeCode()` carry one extra
optional, nullable parameter each (`$codeChallenge`/`$codeVerifier`) beyond the originally sketched
two-argument signatures, added to give PKCE (AC-1.2, provider-agnostic, not a Spotify concern) a
channel through the frozen interface without adding a tenth method. `ProviderTokens` similarly
carries the provider's account id/display name (nullable, populated by `exchangeCode()`, unused by
`refreshToken()`) so identity fetched as part of the OAuth exchange doesn't need its own port
method. Full rationale in `StreamingProviderInterface`'s own docblock.

`App\Service\Streaming\StreamingProviderLocator` resolves an adapter by `key()` from a
`!tagged_iterator app.streaming_provider` — no consumer ever names an adapter class (D-72); an
unknown key raises `UnknownProviderException`. `TrackCandidate` carries the provider's track id,
title, artist, album, duration, whether it is a live recording, and a normalized confidence score —
that score is deliberately naive and provisional (D-83; prompt 12 designs the real one). Every
provider failure arrives as one of `TokenExpiredException`, `RateLimitedException`,
`QuotaExhaustedException`, `NotFoundException`, `RegionRestrictedException`,
`ProviderUnavailableException` (`App\Service\Streaming\Exception\`) — never a raw HTTP status or
provider-shaped error (D-73).

**Adding a provider** means: one directory under `Service/Streaming/`, one `ProviderSetting` row (§6,
prompt 11), one entry in the OAuth redirect configuration, and its credentials in the secret store.
Nothing else in the codebase changes — `App\Tests\Unit\Service\Streaming\
SpotifySymbolIsolationTest` enforces the isolation structurally (D-82) and
`TestDoubleProviderIsDiscoverableTest` proves a second adapter needs zero consumer changes (AC-9.5).
This is the property that makes the Spotify user-cap survivable.

Linking, refresh and the token store live in `App\Service\Streaming\Link\`:
`LinkFlowService` owns the PKCE + single-use `state` lifecycle (Redis-backed, D-76), and
`StreamingTokenManager` is the only thing that ever calls `refreshToken()` — proactive
(`STREAMING_TOKEN_REFRESH_SKEW`), single-flight per account via `symfony/lock` (D-79), and the
only place a `StreamingAccount` moves to `needs_reauth` (D-80). Provider availability is consumed
through `App\Service\Provider\ProviderAvailability` (D-86) — this branch's implementation
(`StaticProviderAvailability`) answers "every registered adapter is available"; prompt 11 replaces
it with the real `ProviderSetting`-backed one and changes no caller.

## 5. setlist.fm integration and the cache

The standard API key allows **2 requests/second and 1,440 requests/day for the entire application**.
That is the binding constraint on how many users Setlistify can serve, so caching is part of the
design rather than an optimization applied later. Shipped by
`docs/specs/2026-08-22-setlistfm-integration.md` (D-56–D-70).

Three tiers, checked in order (`App\Service\Setlist\SetlistCache`):

1. **Redis**, short TTL (`SETLISTFM_CACHE_TTL`, seconds) — absorbs repeat requests inside one user
   session. A durable-tier hit promotes into this tier (AC-6.2).
2. **PostgreSQL** (`setlist_cache`, JSONB payload + `fetched_at` + `stale_after`) — the durable tier.
   `stale_after = NULL` marks data D-59 treats as immutable (a specific past setlist; a page of a
   band's setlist index that is entirely in the past) — never re-fetched. A non-null `stale_after`
   marks volatile data (an artist search; the first page of a band's index) eligible for re-fetch.
3. **setlist.fm** — reached only on a miss in both, through `App\Service\Setlist\SetlistFmClient`,
   gated by `App\Service\Setlist\SetlistFmBudget` (D-61): a Redis-backed per-second token bucket, a
   Redis-backed daily counter keyed by UTC calendar date, and a Redis-shared circuit breaker (D-64)
   that opens after 5 consecutive transient failures. `SetlistFmClient` is the *only* class allowed
   to hold the outbound HTTP client (D-58) — `App\Service\Setlist\SetlistGateway` is the sole public
   entry point every other class in the app depends on, enforced by a static source scan
   (`SetlistGatewayIsOnlyDoorTest`), not just convention.

When the daily budget is spent, the app serves cached data and tells the user plainly that fresh
setlists are unavailable until tomorrow — never a silently empty result. Every setlist-bearing API
response carries a freshness envelope, `{source, fetchedAt, stale, reason}` (D-63,
`App\ApiResource\Setlist\FreshnessEnvelope`): `reason` is one of `null` | `budget_exhausted` |
`rate_limited` | `upstream_unavailable`, and `budget_exhausted` responses include the UTC instant
the budget resets.

Staying current is a **nightly, budget-capped, prioritized job** (`app:setlist:refresh`, D-65) —
never an on-demand check triggered by a user read. It processes bands attached to concerts that are
upcoming or ended within the last 7 days, nearest-to-today first, spends at most
`SETLISTFM_REFRESH_BUDGET_SHARE` of the daily budget, and is guarded by a `symfony/lock` so two
overlapping runs can't double-spend. There is no in-app scheduler component; a deployment cron
entry invokes the command (README.md's operations section).

A band's setlist.fm identity is its MusicBrainz ID (MBID), not its typed name (D-56) —
`App\Service\Setlist\BandIdentityResolver` resolves it via `Band::$normalizedName` as a search hint
only, auto-resolving a single exact normalized match and marking ambiguity/absence as explicit
states (`resolved` | `ambiguous` | `no_presence` | `unresolved`) rather than guessing.

## 6. Provider configuration (backoffice-controlled)

**Built** (`docs/specs/2026-08-22-backoffice-provider-configuration.md`, D-89–D-105).
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

`App\Service\Provider\ProviderRegistry` is the only read path (implements the `ProviderAvailability`
seam prompt 10 shipped empty, D-86/D-89), Redis-cached with explicit invalidation on write
(`App\Service\Provider\ProviderSettingWriter`, the only write path). It is consulted when:

- listing providers a user may link,
- choosing a provider for playlist generation,
- rendering the playback surface on a concert page,
- serving the public `GET /api/config/providers` endpoint the client reads at startup.

Disabling a provider is a **graceful** operation, not a kill: existing linked accounts and generated
playlists remain, the concert page shows a "temporarily unavailable" state, and nothing 500s. This is
the intended response to YouTube exhausting its 10k units/day mid-afternoon. A disabled (or unknown)
provider is a typed `503`/`404` (`App\Service\Provider\ProviderDisabledException`/`App\Service\
Streaming\UnknownProviderException`, D-94) at every wired call site — see US-4 of the prompt 11 spec
for the exhaustive enumeration and prompts 14/17/19's recorded obligation to read the registry too.

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

- **Separate firewall** from the API — session-based login, `ROLE_ADMIN` required, mandatory TOTP
  second factor (`scheb/2fa`), declared before `api` in `security.yaml` since firewall matching is
  first-match-wins (D-48). The API's JWT firewall grants no admin access and an admin session cookie
  grants no API access, in both directions, proven by test (`AdminAccessControlTest`).
- **The owner account is provisioned by console command only.** `ROLE_ADMIN` is unreachable through
  public registration — `bin/console app:admin:create <email> [<password>]` creates or promotes a
  user (and now reports whether 2FA enrollment is still needed, never printing the secret itself),
  `RegisterUserInput` has no `roles` field to attack, and `NoPublicRolesInOpenApiTest` fails the build
  if any public write operation's schema ever grows one. A `ROLE_ADMIN` account with no TOTP secret
  can reach only the enrollment route (D-49) — provisioned and usable are not the same moment.
  Lost-device recovery is `bin/console app:admin:2fa:reset`, console-only, same bar as provisioning.
- **Shipped in this feature** (`docs/specs/2026-08-21-backoffice-foundation.md`): read-only lists and
  detail views for **users, concerts and bands**, plus a read-only **audit log** view and a dashboard
  with three counts (total users, total concerts, concerts in the last 7 days). **Not yet shipped at
  that point**: playlists, generation jobs and setlist-cache health views — those landed with the
  prompts that create those entities.
- **setlist.fm additions** (`docs/specs/2026-08-22-setlistfm-integration.md`, D-67): the dashboard
  gained a setlist.fm panel (today's budget consumption, cache hit rate for today and the trailing 7
  days by tier, total cache entries/songs, the circuit breaker state, and the last nightly refresh
  run's outcome, flagged if over 36 hours stale — AC-11.1–AC-11.3) and a read-only
  `SetlistCacheEntryCrudController` list (AC-11.4). Exactly two audited writes were added — correcting
  a band's setlist.fm MBID and clearing a band's cached setlist associations (AC-11.5) — both routed
  through `AuditLogger` like every other admin write; no "refresh this band now" button exists
  (deliberately: it would be a one-click budget spend, D-67).
- **Write access is narrow**: suspend/unsuspend (toggles `User::$isActive`, revokes every refresh
  token), hard delete (`App\Service\Admin\UserEraser`, transactional, cascades to owned data, leaves
  shared `Band`/`Venue` untouched), reveal-email (rate-limited, audited), the two setlist.fm band
  writes above, and provider configuration (`docs/specs/2026-08-22-backoffice-provider-configuration.md`,
  D-89–D-105) — `enabled`/`playbackMode`/`isDefault`/`notes` on `ProviderSetting`, EDIT only (no
  `NEW`/`DELETE`: rows come from the migration seed), routed through `App\Service\Provider\
  ProviderSettingWriter`. This feature does not become a general-purpose data editor (D-46 makes
  read-only the structural default, not a convention).
- **Every write is audited.** `App\Service\Admin\AuditLogger` is the single write path for
  `AuditLogEntry` — actor, entity, field, old → new, timestamp, IP. The entity is append-only (a
  Doctrine event subscriber rejects update/delete outright) and its digested personal-data fields
  (D-43) let it survive the deletion of the user it describes.
- **Personal data is minimized in every view** (`App\Field\MaskedEmailField`, D-51), with the full
  value behind an explicit, rate-limited, audited reveal action — never a hover or a query parameter.

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
      ├─< StreamingAccount (provider, encrypted accessToken/refreshToken, expiresAt,
      │       scopes, providerAccountId, providerDisplayName, status, linkedAt/updatedAt —
      │       UNIQUE(user, provider))
      └─< AuditLogEntry (as actor, admin only)

Band (setlistfmMbid, setlistfmName, setlistfmResolutionState, setlistfmCheckedAt, setlistfmResolvedAt)
  ├─< Setlist (setlistfmId, eventDate, venue*, tourName, songCount, isEmpty, fetchedAt)
  │       └──< Song (position, setLabel, title, coverOfName/Mbid, withName, info, isTape)
  │
SetlistCacheEntry (cacheKey [UNIQUE], endpoint, payload JSONB, fetchedAt, staleAfter, httpStatus)
  — the receipt `Setlist`/`Song` were derived from (D-60); not FK-linked to Band, keyed by request.

Playlist ──< PlaylistTrack (song ref, provider track id, match confidence, outcome)

ProviderSetting (provider, enabled, playbackMode, isDefault, notes)
```

`PlaylistTrack.outcome` is what makes the "what we couldn't match" report possible — every song in
the source setlist has a row, including the ones that produced no track.

`Concert`, `Band` and `ConcertBand` are built (`docs/specs/2026-08-21-concert-domain-api.md`, D-24–
D-31) — `ConcertBand.billingOrder` keeps a lineup ordered, `Concert.pastAfter` is the derived
boundary instant `App\Service\Concert\ConcertScheduler` computes on every write. `Band`'s five
`setlistfm*` columns, `Setlist`, `Song` and `SetlistCacheEntry` are built
(`docs/specs/2026-08-22-setlistfm-integration.md`, D-56–D-70) — see §5. `StreamingAccount` is built
(`docs/specs/2026-08-22-streaming-port-and-account-linking.md`, D-77/D-78) — see §4 and §11.
`ProviderSetting` is built (`docs/specs/2026-08-22-backoffice-provider-configuration.md`, D-89–
D-105) — see §6. Everything else in this sketch (`Playlist`, `PlaylistTrack`) is still a later
prompt.

## 11. Security posture

- Per-user provider OAuth tokens are **encrypted at rest** (libsodium `xchacha20poly1305`) through a
  custom Doctrine type (`App\Doctrine\Type\EncryptedStringType`), so a database dump is not a set of
  live streaming credentials. The envelope carries a key id (`v1:<keyId>:<base64(nonce‖ciphertext)>`)
  so `TOKEN_ENCRYPTION_KEY` rotates without downtime — see `docs/env-vars.md`.
- **Linking a streaming account** (`docs/specs/2026-08-22-streaming-port-and-account-linking.md`,
  D-74–D-81): OAuth 2.0 Authorization Code with PKCE, exchanged entirely server-side — the client
  never holds a code, verifier or token. `state` is a server-generated, single-use Redis record
  (`STREAMING_LINK_STATE_TTL`, D-76) bound to the user id, provider key, client platform and PKCE
  verifier; consumed atomically on first use, so a replayed callback is rejected. The onward
  redirect (web route or `setlistify://` deep link) carries only a one-time opaque reference, never
  a secret. `StreamingAccount` copies `Concert`'s cross-owner 404 shape exactly (D-27/D-77). An
  unrecoverable refresh failure clears the stored tokens and flips the account to `needs_reauth`
  without deleting the row (D-80); refresh itself is centralised and single-flight per account via
  `symfony/lock` (D-79) — no consumer of the port ever calls `refreshToken()` directly.
- App secrets live in Symfony's secrets vault locally and the PaaS secret store in production. Never
  in the repo; `.env.example` holds names and dummy values only.
- Separate OAuth app registrations for dev and prod, so a leaked development key cannot reach
  production data.
- **Admin firewall and session model** (`docs/specs/2026-08-21-backoffice-foundation.md`, D-42–D-55):
  a second, session-based firewall declared before `api` (D-48), gated by password + mandatory TOTP
  2FA (`scheb/2fa`, forced enrollment for a secret-less account — D-49) with 10 single-use hashed
  backup codes. The session cookie (`admin_session`, D-54) is distinct in name and path from the
  API's `refresh_token` cookie, Redis-backed, `Secure` outside dev, `HttpOnly`, `SameSite=Lax`, with a
  30-minute idle timeout and an 8-hour absolute lifetime. Two Redis-backed rate limiters
  (credentials, IP) plus a 10-consecutive-failure/15-minute account lockout gate the login form;
  `ADMIN_IP_ALLOWLIST`, when non-empty, rejects non-matching sources with a 404 before authentication
  runs (D-42). Every admin route sits under one `access_control` rule requiring `ROLE_ADMIN` (D-46's
  same structural-enforcement principle applied to authorization, not just field lists). Reads never
  go through the API's `ConcertOwnerExtension` — a separate channel (D-47) — so the API's
  cross-owner-404 invariant is never touched by admin traffic.
- **Every admin write is audited, append-only, and survives the subject's own deletion** (D-43):
  `AuditLogEntry.actorId` carries no foreign key, and personal-data fields are stored as keyed
  digests rather than plaintext.
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
