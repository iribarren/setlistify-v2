# Backoffice Provider Configuration

| | |
|---|---|
| **Spec ID** | `2026-08-22-backoffice-provider-configuration` |
| **Backlog prompt** | `docs/prompts/11-backoffice-provider-configuration.md` |
| **Command** | `/feature backoffice-provider-configuration` |
| **Primary agent** | `backend-engineer` (backend only — see D-101) |
| **Branch** | `feature/backoffice-provider-configuration` |
| **Depends on** | `08` — backoffice foundation (merged) · `10` — streaming port and account linking (merged, PR #9) |
| **Status** | **Approved** |

---

## Overview

This feature gives the owner one screen in `/admin` where a streaming provider can be turned off, or
its playback behaviour changed, and have that take effect on the next request — for every client, on
every platform, with no deploy and no app-store release.

It is roughly 400 lines of code and it is one of the highest-leverage things in the roadmap, for
three unrelated reasons:

1. **Operational.** YouTube's Data API allows **10,000 units/day**; a playlist insert costs 50 units
   and a search costs 100 (`docs/external-apis.md` §YouTube). That budget is exhaustible by
   mid-afternoon. When it is, the provider must be disabled in seconds, not in a deploy cycle, and
   users must see "temporarily unavailable" rather than a wall of 500s.
2. **Legal.** Spotify's policy classifies an app by *whether it plays Spotify audio*, and the
   classification — not the revenue model — decides what monetization is permitted. A **Streaming
   SDA** may not sell advertising, sponsorships, or access, at all. A **Non-Streaming SDA** may.
   Setlistify's concert-page iframe embed plausibly makes it the former
   (`docs/external-apis.md` §Spotify). This is harmless today, because the prohibition is on
   commercial uses and there are none. It becomes live the day any revenue is switched on.
   `playbackMode = deeplink` converts the app to a Non-Streaming SDA **at runtime**.
3. **Strategic.** Because of (2), this feature is what keeps the monetization decision (prompt 23)
   *reversible* instead of baked into a shipped release. Prompt 23 gets to choose a model; it does
   not get to require a deploy to undo the choice.

The hard boundary, restated because it is the thing most likely to erode: **this feature configures
behaviour, never credentials.** Client IDs and secrets live in the secret store and nowhere else
(`docs/env-vars.md`). No column here holds one, no admin field renders one, and the public endpoint
is structurally incapable of returning one.

### Honest finding — the D-86 seam exists but has no consumers yet

Prompt 10's D-86 says call sites ask a `ProviderAvailability` interface today, so that prompt 11
"replaces one class and changes no caller". Reading the merged code:

- `backend/src/Service/Provider/ProviderAvailability.php` — the interface. **Shipped.**
- `backend/src/Service/Provider/StaticProviderAvailability.php` — the constant implementation
  (`$locator->has($key)`). **Shipped.**
- **Consumers: none.** `grep -rn "ProviderAvailability" backend/src` matches only those two files.
  `LinkFlowService::start()` calls `$this->locator->get($providerKey)` directly, and
  `StreamingLinkStartProcessor` catches `UnknownProviderException` to produce a 404. No path
  currently asks whether a provider is *available* as distinct from *registered*.

So the seam is a correctly-shaped, correctly-placed **empty socket**. This spec must therefore do two
things prompt 10's handoff described as one: swap the implementation (cheap, exactly as designed) and
**do the call-site wiring prompt 10 intended but did not land** (US-4). This is not a criticism of
prompt 10 — with a constant "everything is available" answer, wiring the seam in was unobservable and
untestable, so it was reasonable to defer. It does mean the "changes no caller" claim in D-86 is not
free here, and the enumeration of call sites in US-4 is load-bearing rather than a formality.

The seam's real value survives intact: the interface, its name, its directory and its documented
contract already exist and are not being invented under deadline, and the callers being touched are
the four listed in AC-4.1 — not "everywhere".

### Load-bearing rules this spec does not reverse

| Rule | Source | How this feature honours it |
|---|---|---|
| Provider state is read at runtime, not baked in | `CLAUDE.md`, `docs/architecture.md` §6 | This is the feature that makes the rule true. `ProviderRegistry` is the only read path (US-10) and it is consulted per request, not at boot |
| The backoffice edits behaviour, never credentials | `CLAUDE.md` | `ProviderSetting` holds `enabled`, `playbackMode`, `isDefault`, `notes` and nothing else. A schema test fails the build on a credential-shaped column (AC-9.1) |
| Provider credentials never leave the secrets layer | `CLAUDE.md`, `docs/env-vars.md` | No credential is read, written, logged or rendered anywhere in this branch (US-9) |
| The streaming port is the only way to reach a provider | `CLAUDE.md`, `docs/architecture.md` §4 | No provider symbol appears here. The registry deals in opaque string keys; `SpotifySymbolIsolationTest` continues to pass unmodified (AC-9.5) |
| The backoffice is not part of the API contract | `CLAUDE.md` | The EasyAdmin screen is server-rendered under `/admin` and never enters the OpenAPI spec. `GET /api/config/providers` is a *public API* resource and is in the spec — a different thing (AC-6.7) |
| Every backoffice write is audited | `docs/architecture.md` §9, prompt 08 D-43 | `ProviderSettingWriter` is the single write path and calls `AuditLogger` per changed field (US-8) |
| Backoffice lists are read-only by default | prompt 08, D-46 | `ProviderSettingCrudController` re-enables `EDIT` **deliberately and only** — no `NEW`, no `DELETE` (AC-3.6): rows are created by migration, because a provider without an adapter is not configurable |

### Existing groundwork reused, not rebuilt

| Existing | Used for |
|---|---|
| `App\Service\Provider\ProviderAvailability` (D-86) | The interface `ProviderRegistry` implements. Not redesigned |
| `App\Service\Streaming\StreamingProviderLocator` (D-72) | The set of provider keys an adapter actually exists for. Stays availability-unaware (D-90) |
| `App\Service\Admin\AuditLogger` (prompt 08) | Every write here. Not extended — `log()` already takes field/old/new |
| `App\Controller\Admin\AbstractAdminCrudController` (D-46) | The base class whose throwing `configureFields()` makes an accidental full-entity dump impossible |
| `App\Service\Setlist\SetlistCacheMetrics` / `SetlistFmBudget` | The precedent for a plain `\Redis` service with explicit keys and no Symfony cache-pool indirection (D-92 follows it) |
| `App\Service\Setlist\SetlistGateway` + `SetlistGatewayIsOnlyDoorTest` | The precedent for "one door, proven by a static test" (AC-10.1 copies its shape) |
| `App\ApiResource\StreamingAccountOutput` + its allowlist test (prompt 10, AC-7.1) | The precedent for a frozen, enumerated response DTO (AC-6.4 copies its shape, more strictly) |
| Problem Details error format (prompt 01) | The typed disabled-provider error (D-94) |

---

## Goals

| # | Goal | Measured by |
|---|---|---|
| G-1 | A provider can be disabled from `/admin` and stops being offered on the next request | AC-1.2, AC-1.3 |
| G-2 | Disabling never destroys or corrupts anything a user already has | AC-5.1–AC-5.4 |
| G-3 | Playback behaviour is runtime configuration, so the SDA classification is reversible | AC-2.1–AC-2.3 |
| G-4 | The operator making a legally-significant change understands it at the moment of the click | AC-3.3, AC-3.4 |
| G-5 | The public config endpoint cannot leak a credential, now or after five more fields accrete | AC-6.4, AC-9.2 |
| G-6 | There is exactly one read path and exactly one write path for provider configuration | AC-10.1, AC-8.1 |
| G-7 | Every provider-config change is reconstructible after the fact | AC-8.2, AC-8.3 |

**Non-goal:** a feature-flag framework. This configures providers. If a second thing ever needs a
runtime flag, that is a separate design conversation, not an extension of this table.

---

## User Stories

### US-1 — Kill a provider in seconds

> **As the** service owner, **I want to** turn a streaming provider off from the backoffice, **so
> that** when YouTube's daily quota is exhausted at 15:00 I can stop offering it in under a minute
> instead of waiting for a deploy.

| # | Acceptance criteria |
|---|---|
| AC-1.1 | `/admin` has a **Providers** section listing one row per `ProviderSetting`, showing `provider`, `enabled`, `playbackMode`, `isDefault` and the last-updated timestamp |
| AC-1.2 | Setting `enabled = false` and saving takes effect on **the next request that reads the registry** — no application restart, no cache warm-up step, no deploy. Verified by a functional test that reads the registry, performs the admin write, reads again in the same process, and sees the new value |
| AC-1.3 | The same is true across processes: the value is invalidated in Redis, not in an in-process array only. Verified by a test that writes through `ProviderSettingWriter` and reads through a **second, freshly constructed** registry instance (AC-10.4) |
| AC-1.4 | Re-enabling is symmetric and equally immediate — nothing needs re-seeding, and no user has to relink |
| AC-1.5 | A provider with no registered adapter (`StreamingProviderLocator::has()` is false) is reported unavailable regardless of its `enabled` flag, and the admin row shows it as such. A settings row is a permission, not a capability |

### US-2 — Change how playback happens without shipping a release

> **As the** service owner, **I want to** switch a provider between in-app embed and a deep-link
> handoff, **so that** I can change Setlistify's Spotify SDA classification at runtime instead of
> baking it into a build.

| # | Acceptance criteria |
|---|---|
| AC-2.1 | `playbackMode` is an enum with exactly three values: `embed`, `deeplink`, `off`. An invalid value is rejected by validation, by the Doctrine type, and by a DB check constraint |
| AC-2.2 | `GET /api/config/providers` reflects a `playbackMode` change on the next request (AC-1.2's mechanism — one cache, one invalidation) |
| AC-2.3 | `enabled` and `playbackMode` are **independent axes** (D-97): a provider may be `enabled = true, playbackMode = off` — usable for linking and playlist creation, with no in-app playback surface. That is precisely the Non-Streaming SDA posture, and a test asserts the combination is valid and representable |
| AC-2.4 | `playbackMode` has no effect on linking, token refresh, or playlist creation. Asserted by test — the axes must not quietly couple |

### US-3 — Understand the consequence before clicking

> **As the** operator changing `playbackMode`, **I want** the screen to tell me what the change means
> legally, **so that** I do not convert the app's SDA classification without knowing I did.

| # | Acceptance criteria |
|---|---|
| AC-3.1 | The `playbackMode` field carries inline help text on the edit form, visible without hovering, expanding, or leaving the page |
| AC-3.2 | The help text names each value's consequence in plain language: `embed` — plays provider audio in-app, likely a **Streaming SDA**, commercial use not permitted; `deeplink` — hands off to the provider's own app, **Non-Streaming SDA**, advertising/sponsorship/paid access permitted; `off` — no playback surface at all |
| AC-3.3 | The help text states the one operational fact that makes this urgent: **if the app is monetized, `embed` is a policy violation for Spotify.** It points to `docs/external-apis.md` §Spotify for the full position but is self-contained enough to act on without opening it |
| AC-3.4 | The `enabled` field carries its own help text stating that disabling is graceful — links and playlists survive and the provider can be re-enabled with no user action |
| AC-3.5 | The help text is asserted by a rendered-HTML test (the same crawl shape as prompt 08's AC-10.3), so a field reorder or a widget swap cannot silently drop it |
| AC-3.6 | The controller enables `EDIT` only. `NEW`, `DELETE` and `BATCH_DELETE` remain disabled from `AbstractAdminCrudController` — rows come from the migration (D-102), and deleting one would leave the registry with a hole rather than a decision |

### US-4 — A disabled provider degrades, it does not break

> **As a** user of Setlistify, **I want** a disabled provider to show as temporarily unavailable,
> **so that** an operational incident looks like a clear message instead of a broken app.

This is the story most likely to be under-implemented, so its call sites are enumerated exhaustively
rather than described. Every row of AC-4.1 is a distinct test.

| # | Acceptance criteria |
|---|---|
| AC-4.1 | **Every consumer is enumerated and covered.** The complete set as of this branch: <br>① `StreamingLinkStartProcessor` → `LinkFlowService::start()` — `POST /api/streaming/link` <br>② `StreamingCallbackController` → `LinkFlowService::completeCallback()` — the OAuth return leg <br>③ `StreamingAccountCollectionProvider` — `GET /api/streaming/accounts` <br>④ `StreamingAccountUnlinkProcessor` — `DELETE /api/streaming/accounts/{id}` <br>⑤ `StreamingTokenManager::validAccessTokenFor()` — proactive refresh <br>⑥ `ProviderConfigProvider` — `GET /api/config/providers` <br>⑦ `StreamingAccountCrudController` — the backoffice's own view of linked accounts |
| AC-4.2 | ① **Starting a link for a disabled provider is refused before any provider call**, with the typed error of D-94 (`503`, Problem Details `type: /errors/provider-unavailable`). No OAuth URL is generated, no `state` is written to Redis, no rate-limit token is consumed against the user for a request that was never going to work |
| AC-4.3 | ② **A flow started before the disable completes normally** (D-95): the account is persisted, the user is returned to the app as usual, and the provider then presents as unavailable. Rationale is asserted in the test's docblock — the user has already granted access at the provider, so refusing here would leave a live third-party grant with no local record of it |
| AC-4.4 | ③ Accounts linked to a disabled provider are **still listed** by `GET /api/streaming/accounts`, unchanged, with `status` untouched. `StreamingAccountOutput` gains **no new field** (D-96) |
| AC-4.5 | ④ **Unlinking always works**, disabled or not. A user must never be trapped in a connection to a provider the operator turned off. Explicitly tested against a disabled provider |
| AC-4.6 | ⑤ Token refresh is **not attempted** for a disabled provider: `StreamingTokenManager` raises the D-94 error instead of calling the adapter. Critically, this **never** transitions the account to `needs_reauth` — a disabled provider is an operator state, not a broken grant (prompt 10, D-80). Asserted by a test that disables the provider, forces an expired token, calls the manager, and asserts both the typed error and the unchanged status |
| AC-4.7 | ⑥ A disabled provider **still appears** in `GET /api/config/providers`, with `enabled: false` (D-99) — the client needs the row to render "temporarily unavailable"; a vanished provider is indistinguishable from a bug |
| AC-4.8 | ⑦ The backoffice can view and unlink accounts for a disabled provider (prompt 10, D-84 unchanged). The admin channel is not gated by user-facing availability |
| AC-4.9 | **No consumer returns a 500 and no consumer fails silently** for a disabled provider. Every path in AC-4.1 either succeeds or returns the one typed error |
| AC-4.10 | **Future consumers are on the record**: prompts 14/17 (generation) and 19 (playback surface) must read the registry. Recorded in `backend/src/Service/Provider/README.md` as an obligation with the same wording weight the current README uses for prompt 11 |

### US-5 — My data survives the incident

> **As a** user with a linked account and generated playlists, **I want** nothing of mine to be
> deleted or altered when a provider is disabled, **so that** an operator's kill switch costs me
> nothing once it is flipped back.

| # | Acceptance criteria |
|---|---|
| AC-5.1 | Disabling writes to `ProviderSetting` only. No `StreamingAccount` row is deleted, modified, or has its tokens cleared. Asserted by comparing full row state before and after |
| AC-5.2 | No `Playlist` row is affected (the entity does not exist yet — the test is written against `StreamingAccount` now and the obligation is recorded for prompt 14) |
| AC-5.3 | Re-enabling restores full function with **no user action**: the same account, the same tokens, the same status, no relink |
| AC-5.4 | A token that expired while the provider was disabled refreshes normally on re-enable — the skipped refreshes of AC-4.6 leave no poisoned state |

### US-6 — The client knows what to render at startup

> **As the** Expo client, **I want** one public endpoint describing which providers are on and how
> playback should render, **so that** I show the right affordances without shipping a build for every
> configuration change.

| # | Acceptance criteria |
|---|---|
| AC-6.1 | `GET /api/config/providers` exists, is **unauthenticated** (`PUBLIC_ACCESS`), and returns `200` with a JSON array |
| AC-6.2 | Each item contains **exactly**: `key`, `displayName`, `enabled`, `playbackMode`, `isDefault`. Nothing else, ever |
| AC-6.3 | The endpoint lists the **intersection** of registered adapters and settings rows (D-99): a settings row with no adapter is omitted, an adapter with no settings row is omitted (deny by default) |
| AC-6.4 | A **strict allowlist test** asserts the exact key set of a live response against a hardcoded literal list, and fails on any added, removed or renamed key. It asserts the top-level key set *and* the absence of any key matching `/secret|token|client_?id|credential|key$|password/i` (AC-9.2) |
| AC-6.5 | The response carries `Cache-Control: no-store` (D-98). The cache that makes this endpoint cheap is server-side; an HTTP cache in front of a kill switch would defeat the feature |
| AC-6.6 | The endpoint is read-only: no `POST`, `PATCH`, `PUT` or `DELETE` is exposed on it, asserted by test |
| AC-6.7 | It appears in the generated OpenAPI spec with its response schema, since it is part of the public API contract — unlike the `/admin` screen, which must not (asserted for both directions) |
| AC-6.8 | `notes` is **never** in this response, and never in any API response (D-103) — it is an internal operational note that may name incidents, people or vendors |

### US-7 — Exactly one default

> **As a** user with more than one linked provider, **I want** a single sensible pre-selection, **so
> that** the app never has to ask me to choose before it can do anything.

| # | Acceptance criteria |
|---|---|
| AC-7.1 | At most one row has `isDefault = true`, enforced by a **partial unique index** (`CREATE UNIQUE INDEX … ON provider_setting (is_default) WHERE is_default`), so the invariant survives a manual `psql` write |
| AC-7.2 | Setting a new default clears the previous one **in one transaction**. A test asserts no intermediate state with two defaults is observable and none with zero defaults is left behind on failure |
| AC-7.3 | Attempting to make a **disabled** provider the default is rejected with a validation error |
| AC-7.4 | **Disabling the current default clears `isDefault` rather than promoting another provider** (D-100). Zero defaults is a valid, representable state; the client then asks the user to choose. Silent promotion would change which service a user's playlists land in during an incident, without anyone deciding it |
| AC-7.5 | `GET /api/config/providers` returns `isDefault: false` on every item when there is no default, and the client is not expected to infer one |

### US-8 — Every change is on the record

> **As the** service owner, **I want** every provider-configuration change audited with before and
> after values, **so that** "when did we become a Non-Streaming SDA, and who decided?" has an answer.

| # | Acceptance criteria |
|---|---|
| AC-8.1 | All writes go through `App\Service\Provider\ProviderSettingWriter`. A static test asserts no other class in `src/` persists or flushes a `ProviderSetting` (AC-10.1's mechanism, applied to writes) |
| AC-8.2 | One `AuditLogEntry` per **changed field**, with `field`, `oldValue`, `newValue`, actor, timestamp and IP. Unchanged fields produce no entry — an audit log full of no-ops is an audit log nobody reads |
| AC-8.3 | Values are recorded **literally, not digested** (D-104): `enabled`/`playbackMode`/`isDefault` are not personal data, and D-43's digest exists to survive user deletion, which does not apply. `notes` **is** digested, since an operator may type anything into it |
| AC-8.4 | `subjectType` is `ProviderSetting` and `subjectId` is the provider key, so the audit log is filterable to "everything that ever happened to Spotify's configuration" |
| AC-8.5 | The audit write and the setting write are in **one transaction**, and the cache invalidation happens **after** a successful commit (D-92) — a rolled-back write must never leave an invalidated cache serving a value that was never persisted |
| AC-8.6 | Entries appear in the existing audit log view with no changes to that controller |

### US-9 — Configuration cannot leak a credential

> **As the** person responsible for security, **I want** it to be structurally impossible for this
> feature to hold or render a secret, **so that** the boundary survives future edits by people who
> have not read this document.

| # | Acceptance criteria |
|---|---|
| AC-9.1 | A schema test enumerates `provider_setting`'s columns against a hardcoded expected list and fails on any addition — with the failure message naming the credential rule, so the person who hits it reads the reason and not just the diff |
| AC-9.2 | The public endpoint test (AC-6.4) asserts no credential-shaped key appears in the response |
| AC-9.3 | `ProviderSettingCrudController::configureFields()` enumerates exactly four editable fields plus read-only timestamps; the D-46 base class makes "expose everything" unreachable, and a rendered-HTML test asserts the field set |
| AC-9.4 | No class in this branch injects `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET`, or any `%env(...)%` credential parameter. Asserted by a static test over the branch's own classes |
| AC-9.5 | `SpotifySymbolIsolationTest` (prompt 10, D-82) passes **unmodified**. The registry deals in opaque string keys; the string `"spotify"` appears in this branch only in the migration's seed data and in test fixtures, neither of which is a symbol |

### US-10 — One read path, one write path

> **As an** engineer maintaining this later, **I want** exactly one way in and one way out of
> provider configuration, **so that** "is this cached?" and "was this audited?" have single answers.

| # | Acceptance criteria |
|---|---|
| AC-10.1 | A **static test** asserts `ProviderRegistry` and `ProviderSettingWriter` are the only classes referencing `ProviderSetting` or `ProviderSettingRepository` — copying `SetlistGatewayIsOnlyDoorTest`'s shape exactly, including its allowlist-of-permitted-files structure |
| AC-10.2 | `ProviderRegistry implements ProviderAvailability`. `StaticProviderAvailability` is **deleted** (D-89) — leaving a second implementation registered would make "which one is wired?" a live question in an incident |
| AC-10.3 | Reads are cached in Redis under one key holding the whole snapshot (D-92), so a page needing three providers' flags costs one round trip |
| AC-10.4 | **Invalidation on write is explicit and immediate**, verified by a test that writes and then reads through a freshly constructed registry (AC-1.3). TTL is a safety net for correctness bugs, not the correctness mechanism |
| AC-10.5 | If Redis is unavailable, the registry **falls back to a direct database read** and logs a warning (D-105). It does not throw and does not fail closed: provider configuration is not a security control, and disabling every provider during a Redis blip is a worse outcome than a slow request |
| AC-10.6 | The registry returns immutable value objects, never managed Doctrine entities (D-93) |

---

## Technical Approach

### Sub-projects touched

| Sub-project | Touched? | What |
|---|---|---|
| `backend/` | **Yes** | Entity, migration, registry, writer, public endpoint, admin controller, tests |
| `frontend/` | **No** | D-101 — consumption deferred to prompts 16/19 |
| `docker/` | No | No new service; Redis and Postgres already present |
| `docs/` | **Yes** | `architecture.md` §6/§9, `external-apis.md` §Spotify, `env-vars.md` |

### Backend shape

```
backend/src/
├─ Entity/
│  └─ ProviderSetting.php              ← behaviour flags only (D-89); no credential column, ever
├─ Repository/
│  └─ ProviderSettingRepository.php    ← referenced by exactly two classes (AC-10.1)
├─ Service/Provider/
│  ├─ ProviderAvailability.php         ← UNCHANGED interface from prompt 10 (D-86)
│  ├─ StaticProviderAvailability.php   ← DELETED (D-89)
│  ├─ ProviderRegistry.php             ← the only read path; implements ProviderAvailability
│  ├─ ProviderSettingWriter.php        ← the only write path; audits + invalidates (D-92, US-8)
│  ├─ ProviderConfig.php               ← immutable snapshot VO (D-93)
│  ├─ PlaybackMode.php                 ← backed enum: embed | deeplink | off
│  ├─ ProviderDisabledException.php    ← the typed error (D-94)
│  └─ README.md                        ← updated: seam filled, future obligations (AC-4.10)
├─ ApiResource/
│  ├─ ProviderConfigResource.php       ← GET /api/config/providers, PUBLIC_ACCESS
│  └─ ProviderConfigOutput.php         ← five public fields, frozen (AC-6.2)
├─ State/Provider/
│  └─ ProviderConfigProvider.php       ← reads the registry, maps to output
├─ Controller/Admin/
│  └─ ProviderSettingCrudController.php ← extends AbstractAdminCrudController; EDIT only
└─ migrations/
   └─ VersionXXXXXX.php                ← table + partial unique index + seed rows (D-102)
```

Call sites changed (the US-4 wiring, and the whole of the diff outside the new files):

| File | Change |
|---|---|
| `Service/Streaming/Link/LinkFlowService.php` | `start()` consults `ProviderAvailability` before touching the limiter or locator (AC-4.2) |
| `State/Processor/StreamingLinkStartProcessor.php` | Maps `ProviderDisabledException` → 503; `UnknownProviderException` → 404 stays |
| `Service/Streaming/Link/StreamingTokenManager.php` | Refuses refresh for a disabled provider without changing status (AC-4.6) |
| `Controller/Admin/DashboardController.php` | Adds the Providers menu entry |
| `config/services.yaml` | Registry replaces the static implementation for the `ProviderAvailability` alias |

`StreamingProviderLocator` is **not** touched (D-90).

### Data model addition

| Column | Type | Notes |
|---|---|---|
| `id` | int, PK | |
| `provider` | varchar, **unique** | `spotify` \| `youtube` \| `apple` — a plain string, not an enum: adding a provider must not need a migration |
| `enabled` | bool, not null | Offered to users at all |
| `playback_mode` | varchar, not null, check constraint | `embed` \| `deeplink` \| `off` |
| `is_default` | bool, not null | **Partial unique index** where true (AC-7.1) |
| `notes` | text, nullable | Admin-only, never in any API response (AC-6.8) |
| `created_at` / `updated_at` | timestamptz | |

Matches `docs/architecture.md` §6 exactly. `displayName` is deliberately **not** a column — it comes
from the adapter (`StreamingProviderInterface`), so an operator cannot rename Spotify in a way that
misrepresents the provider.

### Caching

One Redis key (`provider:settings:v1`) holding the serialized snapshot of all rows, written on read
miss and **deleted** on any successful write. A short TTL (`PROVIDER_SETTINGS_CACHE_TTL`, default
300s) exists only as a backstop against an invalidation bug; nothing depends on it for correctness
(AC-10.4). Redis is reached through the same plain `\Redis` service `SetlistCacheMetrics` already
uses, for consistency with the one existing precedent in this codebase.

### New environment variable

| Variable | Default | Purpose |
|---|---|---|
| `PROVIDER_SETTINGS_CACHE_TTL` | `300` | Safety-net TTL on the settings snapshot. Correctness comes from explicit invalidation (D-92) |

Added to `docs/env-vars.md` and `.env.example` in the same branch.

---

## Decisions

Continuing from prompt 10's **D-88**.

**D-89 — `ProviderRegistry` implements `ProviderAvailability`, and `StaticProviderAvailability` is
deleted in the same commit.**
Two registered implementations of a runtime kill switch is one implementation too many: the question
"which one is actually wired?" must never be askable at 15:00 on the day YouTube's quota runs out.
The interface itself is unchanged — prompt 10 designed it correctly and this branch does not
redesign it.

**D-90 — `StreamingProviderLocator` stays availability-unaware.**
The locator answers "does an adapter exist for this key?" (D-72). Availability is a different
question with a different answer source. Folding them together would change `has()`'s meaning for
`TestDoubleProviderIsDiscoverableTest`, make the locator depend on the database, and destroy the one
place that can distinguish "no adapter" (a 404) from "turned off" (a 503).

**D-91 — Prompt 10's D-86 seam is wired to real call sites in this branch.**
Per the Overview's honest finding, the interface shipped without consumers. This spec therefore owns
both the swap and the wiring. Scope impact is small and bounded — four production files, listed in
the Technical Approach — but it is real work and is not hidden inside "replace one class".

**D-92 — Correctness comes from explicit invalidation; TTL is a backstop.**
The brief names the tension exactly: caching a value whose entire purpose is to change fast. The
resolution is that every write path deletes the key **after** the transaction commits, and the tests
assert freshness across a new registry instance rather than sleeping past a TTL. A test that passes
by waiting is a test that hides a broken invalidation.

**D-93 — The registry returns immutable snapshots, not entities.**
Handing out managed entities would give every consumer a write handle to the thing D-89 just made
single-pathed, and would put Doctrine's identity map in front of the cache. A `ProviderConfig` VO
also makes AC-10.1's static test meaningful: nothing outside two classes can even *name* the entity.

**D-94 — A disabled provider is a typed `503`, an unknown provider stays a `404`.**
They are different facts and must stay distinguishable: 503 is honest ("this exists, it is off right
now, try later") and matches the "temporarily unavailable" copy the client renders; 404 for an
unknown key is prompt 10's existing behaviour and is unchanged. Enumeration is not a concern —
`GET /api/config/providers` publishes the provider list deliberately.

**D-95 — An OAuth flow in flight when a provider is disabled completes and persists.**
The alternative — rejecting the callback — leaves the user with a live grant at the provider and no
local record of it, which is strictly worse: they cannot unlink what we never stored. The persisted
account is inert while the provider is disabled (AC-4.6) and works immediately on re-enable
(AC-5.3). This is the "no data loss, no silent failure" criterion applied to the awkward case rather
than the easy one.

**D-96 — `StreamingAccountOutput` gains no availability field.**
Prompt 10 froze that DTO behind an allowlist test (its AC-7.1). Availability belongs to the
provider, not to the account, and duplicating it into a per-account field creates two sources for one
truth that can disagree within a single render. The client joins the accounts list to
`/api/config/providers` by `key`.

**D-97 — `enabled` and `playbackMode` are independent axes.**
`enabled` governs linking, refresh and (later) generation. `playbackMode` governs the concert page's
playback surface only. Collapsing them would make `playbackMode = off` mean "provider off", which
destroys the one configuration this feature exists to make reachable: **enabled, generating
playlists, no in-app audio** — a Non-Streaming SDA that still works.

**D-98 — The public endpoint sends `Cache-Control: no-store`.**
An HTTP cache in front of a kill switch is the kill switch's failure mode. The endpoint is cheap
because of the server-side Redis snapshot, which we can invalidate; a CDN or browser cache is one we
cannot.

**D-99 — Disabled providers appear in the response with `enabled: false`; providers with no adapter
do not appear at all.**
The client needs a row to render "temporarily unavailable" — a provider that disappears from the
payload is indistinguishable from a client bug at exactly the moment clarity matters. Conversely a
settings row with no adapter is not a product state (it is a seeded placeholder, D-102) and must not
be offered. Deny by default: no settings row means unavailable, which is what makes the YouTube row
safe to seed before prompt 18 lands.

**D-100 — Disabling the default clears the default; it does not auto-promote.**
Auto-promotion would silently change which service a user's playlists are created in, during an
incident, with no operator deciding it and no audit entry naming the decision. Zero defaults is a
valid state the client already has to handle (a user with one linked provider has no ambiguity to
resolve anyway).

**D-101 — This feature is backend-only; client consumption is explicitly deferred.**
The brief assigns `backend-engineer` alone, and the call is correct. Rendering "temporarily
unavailable" and honouring `playbackMode` are UI states belonging to screens that do not exist yet:
the playlist screens are prompt 16, and the playback surface is prompt 19 (which prompt 10's Out of
Scope already assigns `playlistEmbedUrl()`/`playlistDeepLink()` to). Building client behaviour now
would mean inventing placeholder screens to hang it on, then rewriting them.

What this branch **does** ship to make that later work a wiring exercise rather than a design one:
the endpoint, its frozen schema, its OpenAPI entry, and a note in the frontend README naming
`/api/config/providers` as the startup read. What it does **not** ship: any change under
`frontend/`. Consequence, accepted knowingly: **between this branch and prompt 16, disabling a
provider is enforced by the backend but not yet reflected in client copy** — the user gets a clean
typed error instead of a designed empty state. That is acceptable because the app has no playlist UI
to degrade yet, and the enforcement (the part that protects quota and legal posture) is live from
this branch.

**D-102 — The migration seeds `spotify` and `youtube` with deliberately asymmetric defaults.**
`spotify`: `enabled = true`, `playbackMode = embed`, `isDefault = true` — the current, working,
unmonetized state. `youtube`: `enabled = false`, `playbackMode = off`, `isDefault = false` — the row
exists so prompt 18 is a flag flip rather than a migration, and D-99's adapter-intersection rule
keeps it invisible until an adapter exists. `apple` is not seeded: it is not on the roadmap.

**D-103 — `notes` is admin-only and never leaves `/admin`.**
An operator writing "disabled 22/08 after Google rejected the quota bump, see thread with $NAME"
must not have that published on an unauthenticated endpoint. It is also digested in the audit log
(AC-8.3) for the same reason.

**D-104 — Audit values are recorded literally, not digested.**
D-43's digest exists so audit entries survive the deletion of the user they describe. A boolean flag
and an enum describe no user. Digesting them would make the audit log unreadable for precisely the
question it exists to answer: *what was the playback mode on the day we enabled monetization?*
`notes` is the exception, per D-103.

**D-105 — The registry fails open to the database, not closed, when Redis is down.**
This deliberately differs from `RateLimiterGuard` (fails closed) and `SetlistFmBudget` (fails
closed), and the difference is principled: those protect a security boundary and a hard external
quota, where the safe answer to "I don't know" is "no". Provider configuration is neither. Failing
closed here would turn a Redis blip into a total provider outage — the registry would cause the
incident it exists to mitigate. The fallback path logs a warning so the degradation is visible.

---

## Out of Scope

| Not in this feature | Why / where it goes |
|---|---|
| **Provider credentials in any form** | Permanently out. Secret store only (`docs/env-vars.md`, `CLAUDE.md`). This is not a deferral |
| **Per-user provider overrides** | This is global configuration. Per-user entitlement is prompt 22 |
| **Choosing a monetization model** | Prompt 23. This feature makes the choice reversible; it does not make it |
| **A general feature-flag framework** | Provider configuration is a bounded domain with four fields. A flag framework is a different design with different failure modes |
| **Client rendering of unavailable states and `playbackMode`** | D-101 — prompts 16 (playlist UI) and 19 (playback surface) |
| **The YouTube adapter** | Prompt 18. This branch seeds a disabled row (D-102); it ships no provider code |
| **Playlist generation reading the registry** | Prompts 14/17. Recorded as an obligation (AC-4.10), not built — there is no pipeline to wire |
| **The concert page playback surface** | Prompt 19. `playbackMode` is stored and published here; nothing renders it yet |
| **Admin-triggered cache flush button** | Invalidation is automatic on write (D-92). A manual flush button would be a supported workaround for a bug we would rather fix |
| **Provider health checks / automatic disabling on quota exhaustion** | Tempting and wrong for now: an automatic disable is an unaudited actor. A human flips the switch and the audit log names them. Revisit only with an explicit design for the audit trail |
| **Changing `StreamingAccountOutput`** | D-96 |
| **Scheduling / time-boxed disables** ("off until 00:00 UTC") | Real operational value, but it needs a scheduler and an expiry semantics discussion. Manual re-enable is sufficient for the incident this feature targets |

---

## Dependencies

**Must be true before implementation begins**

| Dependency | Provides | Status |
|---|---|---|
| **Prompt 08 merged — backoffice foundation** | `AbstractAdminCrudController` (D-46) with its throwing `configureFields()`, `AuditLogger` as the single audit write path, the 2FA-gated admin firewall, the audit log view, the `DashboardController` menu | **Met** — merged |
| **Prompt 10 merged — streaming port and account linking (PR #9)** | `ProviderAvailability` (D-86), `StreamingProviderLocator` (D-72), `StreamingAccount`, `LinkFlowService`, `StreamingTokenManager`, `SpotifySymbolIsolationTest`, and the four call sites this branch wires | **Met** — merged |
| Prompt 09's `SetlistGatewayIsOnlyDoorTest` | The static single-door test shape AC-10.1 copies | **Met** |
| Prompt 09's `SetlistCacheMetrics` | The plain-`\Redis` service precedent D-92 follows | **Met** |
| Redis reachable and shared across processes | The settings snapshot and AC-1.3's cross-instance test | **Met** — compose `redis`, healthchecked |
| Problem Details error format (prompt 01) | D-94's typed 503 | **Met** |
| Doctrine migrations configured | The table, the partial unique index, the seed rows | **Met** |

Every dependency is met. **This feature has no blocking unknowns and can start on approval.**

**Depended on by**

- **Prompt 14 / 17 (playlist generation)** — must select a provider through the registry, not through
  the locator (AC-4.10).
- **Prompt 16 (playlist fast-mode UI)** — first consumer of `GET /api/config/providers`; renders the
  unavailable state D-101 defers.
- **Prompt 18 (YouTube adapter)** — its go-live is flipping the seeded row's `enabled` (D-102). If it
  needs a migration, this feature failed.
- **Prompt 19 (concert page player embed)** — reads `playbackMode` to choose embed vs deep link.
- **Prompt 23 (monetization spike)** — must record the then-current `playbackMode` values as an input
  to the decision (R-4).

---

## Risks

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| R-1 | **A consumer is missed.** "Graceful" is per-call-site, and a missed one surfaces during an incident — the exact moment this feature is supposed to help | **High** — it is the failure mode that makes the feature worse than not having it | AC-4.1 enumerates all seven call sites from a read of the merged code, each with its own test. The static test (AC-10.1) catches a *new* direct reader; code review checks the enumeration against `grep -rn "StreamingProviderLocator" src` at merge time |
| R-2 | **Cache invalidation is subtly wrong** and a disabled provider keeps being offered | **High** | D-92: invalidate after commit, never rely on TTL. AC-1.3/AC-10.4 test across a fresh registry instance, so an in-process-only invalidation fails the build |
| R-3 | **The public endpoint accretes fields and eventually leaks one.** It is unauthenticated and permanent | **High over time** | AC-6.4's allowlist test is strict by construction — a hardcoded literal key set plus a credential-name regex. Adding a field requires editing the test, which is the review trigger |
| R-4 | **`playbackMode`'s legal meaning is forgotten** and someone sets `embed` after monetization ships | **High** — a policy violation, not a bug | AC-3.1–AC-3.5 put the consequence on the screen at the moment of the click, asserted by test. R-4's remaining exposure is deliberate: prompt 23 must record the then-current values in its own spec |
| R-5 | **Redis is down and the registry fails closed**, disabling every provider | Medium | D-105 fails open to the database with a logged warning, and AC-10.5 tests it |
| R-6 | **Two defaults exist** after a concurrent write | Medium | AC-7.1's partial unique index makes it unrepresentable at the storage layer, not just guarded in application code |
| R-7 | **The D-86 wiring gap turns out to be larger than four files** once implementation starts | Low–Medium | The four files are named from a read of the merged code, not estimated. If a fifth appears, it belongs in AC-4.1 and in the tests — not silently absorbed |
| R-8 | **The audit log fills with no-op entries** and stops being read | Low | AC-8.2: one entry per *changed* field only |
| R-9 | **Scope creep into automatic disabling** ("if quota is gone, turn it off for me") | Low | Out of Scope, with the reason stated: an automatic actor has no accountable name in the audit log |

---

## Open Questions — for the user to resolve on approval

1. **Should a disabled provider's OAuth callback still persist the account (D-95)?**
   Recommendation: **yes, persist**. The user has already consented at the provider; refusing leaves
   a live grant they cannot see or revoke from our side. Alternative, if you prefer strictness: reject
   with a `provider_disabled` reason and accept the dangling grant.
2. **Should `playbackMode = deeplink` be the seeded default for Spotify instead of `embed` (D-102)?**
   Recommendation: **`embed`**, matching today's actual behaviour and `docs/architecture.md` §7 —
   the app is unmonetized, so `embed` is harmless now, and seeding `deeplink` would ship a product
   change disguised as a default. The moment monetization is considered, prompt 23 flips it.
3. **Confirm zero-defaults is acceptable (D-100, AC-7.4).** It means disabling Spotify today leaves
   the app with no default provider until someone sets one. The alternative (auto-promote) is a
   silent behavioural change during an incident. Recommendation: **accept zero defaults**.
4. **Is the deferred client work (D-101) acceptable as stated?** Between this branch and prompt 16, a
   disabled provider produces a typed 503 rather than designed copy. Recommendation: **accept** —
   there is no playlist UI to degrade yet, and enforcement is what protects quota and legal posture.

---

## Documentation Updates (in this branch, per `CLAUDE.md`)

| Doc | Change |
|---|---|
| `docs/architecture.md` §6 | Mark `ProviderSetting`/`ProviderRegistry` as built, with the D-89–D-105 range; update §10's data-model sketch (`ProviderSetting` is no longer "a later prompt") |
| `docs/architecture.md` §9 | Add provider configuration to the backoffice's narrow write list, alongside suspend/erase/reveal and the two setlist.fm writes |
| `docs/external-apis.md` §Spotify | The "Mitigation, already built in" paragraph becomes literally true — name the admin screen and `playbackMode`'s runtime effect |
| `docs/external-apis.md` §YouTube | Name `ProviderSetting.enabled` as the shipped kill switch for quota exhaustion |
| `docs/env-vars.md` + `.env.example` | `PROVIDER_SETTINGS_CACHE_TTL`; restate that no provider credential is ever configurable from `/admin` |
| `backend/src/Service/Provider/README.md` | Rewrite: the seam is filled, `StaticProviderAvailability` is gone, and prompts 14/17/19 carry the obligation to read the registry (AC-4.10) |
| OpenAPI spec | Regenerates from `ProviderConfigResource` — not hand-listed anywhere (AC-6.7) |
| `frontend/README.md` | One line naming `/api/config/providers` as the startup read for whichever prompt consumes it (D-101) |

---

## Approval

**This spec is not approved yet.** Please review — in particular the four Open Questions, the
call-site enumeration in AC-4.1, and D-101's decision to defer client work — and confirm before
`feature/backoffice-provider-configuration` is created and any code is written.
