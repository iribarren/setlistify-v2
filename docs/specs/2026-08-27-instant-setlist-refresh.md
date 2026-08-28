# FEATURE — Instant, entitled, on-demand setlist refresh

| | |
|---|---|
| **Spec ID** | `2026-08-27-instant-setlist-refresh` |
| **Backlog prompt** | *(none — user-initiated, from `docs/investigations/2026-08-27-boikot-setlist-not-found.md`. Overlaps prompt `22` — see D-267)* |
| **Command** | `/feature instant-setlist-refresh` |
| **Primary agent** | `backend-engineer` (the bulk) · `frontend-engineer` (US-10 only) — one branch, one PR |
| **Type** | **FEATURE — implementation follows this document directly.** Branch `feature/instant-setlist-refresh` |
| **Depends on** | `09` setlist.fm integration (merged — `SetlistGateway`, `SetlistCache`, `SetlistFmBudget`, `BandIdentityResolver`, D-56–D-70) · `08` backoffice foundation (merged — `AuditLogger`, `AbstractAdminCrudController`, D-43, D-46, D-47) · `14`/`16` playlist fast mode (merged — `PlaylistGenerationJobResource`, `noSetlistCause`, the polling contract) |
| **Amends** | `docs/specs/2026-08-22-setlistfm-integration.md` — **D-65** and **D-67** are narrowed, not reversed (D-254, D-255); **D-57**'s set of permitted choosers is widened under structural safeguards (D-270 – D-272) |
| **Decisions** | **D-254** – **D-280** |
| **Status** | **Draft — review requested** |

---

## Amendment to `2026-08-22-setlistfm-integration.md`

That spec rejected this feature shape by name. This section states the amendment explicitly, up
front, so the decision record reads correctly from either document.

| Decision, as written on 2026-08-22 | What changes |
|---|---|
| **D-65** — *"Freshness is a nightly, prioritized, budget-capped job — never an on-demand check… On-demand per-user checks are rejected outright."* | **Narrowed.** It remains the rule for **the default, unentitled path**, which is every user today. A single, entitled, band-scoped, cooldown-bounded exception is carved out by **D-254**. The rejection's actual argument — *on-demand checks scale with traffic* — is answered by making the exception not scale with traffic (D-259). |
| **D-67** — *"Notably absent: a 'refresh this band now' button. It would be a one-click budget spend on the most dangerous resource in the product."* | **Narrowed for the API, unchanged for the backoffice.** There is still no "refresh now" button in `/admin` (**D-255**). The API gains one action, and it is not one-click-unbounded: it is entitlement-gated, cooldown-gated, per-user-capped and budget-reserve-gated before it ever reaches `SetlistFmBudget`. |
| **AC-10.6** — *"No user-facing read path ever triggers a speculative 'has this band played since?' check."* | **Unchanged and load-bearing.** This feature adds no behaviour to any read path. `GET` stays free. The refresh is an explicit `POST` a human deliberately issues — the opposite of speculative (D-254). |
| **D-62** — *"A web request never queues on the rate limiter."* | **Unchanged, and it is the reason this action is asynchronous** (D-256). The `POST` returns before any outbound call is attempted. |
| **D-57** — *"The disambiguation choice is stored on the shared `Band`, not per user… First resolver wins."* | **Widened in who may choose, unchanged in what is stored.** The choice is still one shared value on one shared row, still first-chooser-wins, still correctable by exactly one audited operator action. What changes is that an entitled user may now be the chooser, and only ever by selecting from a server-produced candidate set, only ever on a band that holds **no** MBID, and only ever once (**D-270**, **D-271**, **D-276**). R-4 of that spec — *"a wrong shared disambiguation propagates to every user"* — is unchanged in kind and re-argued at greater width in R-3 and R-11 below. |
| **AC-2.4** — *"The user's chosen MBID is stored on the shared `Band` row (D-57)"* | **Unchanged, and now literally true.** Spec 09's US-2 was written as *"As a user, I want to say which 'Nirvana' I mean"*; the implementation shipped that choice as an operator-only backoffice action. This amendment builds the user-facing half that AC-2.4's wording always assumed. |
| **D-56, D-59, D-60, D-61, D-63, D-64, D-66, D-68** | **Untouched.** MBID-is-identity, the two freshness classes, dual storage, the single fail-closed gate, the freshness envelope, the shared breaker, reference-data-not-owned and Redis-only metrics all hold exactly as written. In particular **D-56 stands whole**: no code path re-derives the identity of a band that already carries an MBID, and a user's pick is a write into a vacancy, never an overwrite (D-270). This feature spends from the **same** shared pool through the **same** gate; there is no separate quota, no second budget, no bypass (D-254). |
| Spec 09 *Out of Scope* row: *"A user-facing 'refresh now' control, and its backoffice equivalent"* | The **backoffice equivalent** stays out of scope permanently (D-255). The user-facing control moves in scope here. |

**Two new decision entries, D-254 and D-255, are added to `2026-08-22-setlistfm-integration.md` in
this branch**, alongside a pointer on D-65 and D-67 — *already done*. **D-270 – D-272 are added to
the same document in this branch**, alongside a scope note on **D-57** and a widened mitigation note
on **R-4**. Nothing is deleted from that document: a decision record that edits its own history is
not a record.

---

## Overview

### What this feature is

A user tracked a concert for **Boikot**. Playlist generation reported no setlist, although the band
demonstrably has setlists on setlist.fm. The investigation
(`docs/investigations/2026-08-27-boikot-setlist-not-found.md`) found the mechanism: the band's
setlist.fm identity state is stuck, and *nothing in the product will ever unstick it on its own*
except a 30-day nightly recheck that applies to `no_presence` bands only. An `ambiguous` band is
never automatically re-resolved by anything. The only escape hatch is an operator in `/admin`.

That is a dead end with no user-visible exit, and it was designed that way on purpose: the budget is
1,440 requests per day for the entire application, and a "try again" button is the classic way to
spend it. This feature builds the exit anyway — narrowly, and paying for it with throttles rather
than with a quota carve-out.

An **entitled** user, on a band that appears on **one of their own concerts**, may trigger one
on-demand refresh. That refresh:

1. **forces identity re-resolution** even from `ambiguous` or `no_presence` — the guard clause in
   `BandIdentityResolver::ensureResolved()` that returns early for those states is bypassed by a new,
   separate, deliberately-hard-to-call method;
2. **forces a live index fetch**, skipping the cache's freshness short-circuit rather than waiting for
   the 1-day `staleAfter` on page 1 to lapse;
3. **still passes through `SetlistFmBudget` unchanged.** A busy budget refuses an entitled user
   exactly as it refuses everyone else. There is no priority lane.

And when that refresh comes back **`ambiguous`** — several bands on setlist.fm answer to this name —
the same user may **resolve the ambiguity themselves**, by choosing one of the candidates the search
returned. That choice writes the MBID onto the shared `Band` and completes the refresh (D-270 –
D-280).

### What it covers, and what it costs

**It fixes the Boikot case**, including the shape the investigation thinks most likely. All four
shapes of stuck now have a user-visible exit:

| State today | What the feature does | Fixes the user's problem? |
|---|---|---|
| `unresolved` (a first attempt degraded on budget, rate limit or an open breaker) | Re-attempts the search | **Yes** — and immediately, instead of waiting for tonight |
| `no_presence` (band was absent from setlist.fm then, present now; or the first check was a false negative) | Re-attempts the search | **Yes** — instead of waiting up to 30 days |
| `resolved` but the index is stale (a setlist uploaded this morning) | Re-fetches page 1 live | **Yes** — instead of waiting for tonight |
| `ambiguous` | Re-attempts the search, reports the same ambiguity **with the candidate list**, and lets the user pick the right band — which resolves the shared `Band` and fetches its setlists | **Yes** (D-270) — this is the state no automatic path in the product has ever escaped |

This is the amendment's whole point, and it is worth naming the price rather than burying it. D-57
stores the disambiguation choice on the **shared** `Band`: one user's pick becomes every user's
setlists for that band. Until now exactly one class of actor could make that pick — an operator, in
an audited, 2FA-gated, IP-allowlisted session — and spec 09's R-4 accepted the blast radius on that
basis. Widening the chooser to an entitled user does not shrink the blast radius; it removes the
2FA-gated operator from in front of it. Three structural properties are what replace that operator,
and each is a decision rather than a hope:

| Safeguard | Decision | What it rules out |
|---|---|---|
| The user picks from a **server-produced candidate set**, never a free-text MBID | D-271 | Pointing a band at an arbitrary artist the search never proposed |
| The write is only ever into a **vacancy** — a band with no MBID | D-270 | Overwriting a resolved identity, i.e. re-deriving it (D-56) or silently orphaning cached setlists |
| A band is resolvable **once**; afterwards the operator's correction is the only write | D-276 | Flip-flopping a shared row between candidates, and any repeat-cost path |

What remains, honestly: a user can pick the wrong "Boikot", and every user of that band gets that
band's setlists until an operator corrects it. That is spec 09's R-4 exactly — same failure, same
one-action audited fix — reached by a wider set of actors, all of them named in an audit entry
(D-274). See R-11.

### What it is still not

It is not a general band-identity editor. A user cannot type an MBID, cannot re-open a resolved
band, cannot correct a wrong resolution — their own or anyone else's — and cannot resolve a band that
is not on one of their concerts. Every one of those remains the operator's audited action.

### What the code looks like today

| Symbol | Today | This feature |
|---|---|---|
| `App\Service\Setlist\BandIdentityResolver::ensureResolved()` | Returns early for `resolved`, `ambiguous`, `no_presence`; only `unresolved` searches | Untouched. A new sibling `forceResolve()` is added (D-263) |
| `BandIdentityResolver::recheckNoPresenceIfDue()` | `no_presence` only, ≥30 days, nightly job only | Untouched |
| `App\Entity\Band::resetResolution()` | Exists, used by `recheckNoPresenceIfDue()` | Reused by `forceResolve()` — no entity change |
| `App\Entity\Band::resolveTo($mbid, $setlistfmName, $now)` | The single write that sets an MBID — called by `BandIdentityResolver` on an auto-resolve and by `BandCrudController::performCorrectMbid()` on an operator correction | **Unchanged, and reused verbatim** by the user's pick (D-279). One write path for identity, now with three callers instead of two |
| `BandCrudController::performCorrectMbid()` | Free-text MBID, any state, audited as `correct_band_mbid` | **Untouched.** It stays the only way to overwrite a resolved identity, and the only reversal of a user's pick (D-273) |
| `App\ApiResource\Setlist\BandSearchCandidateOutput` | `{mbid, name, sortName, disambiguation}`, already returned by `BandSearchProvider` and `BandSetlistsProvider` | Reused as-is, and its `mbid` becomes the only accepted input to the pick (D-271) |
| `App\Service\Setlist\SetlistGateway` | `searchArtist()`, `fetchArtistSetlistsPage($mbid, $page, ?$waitOverrideSeconds)`, `fetchSetlistDetail()` — the only door (D-58) | Gains two force-live siblings. **Still the only door** |
| `App\Service\Setlist\SetlistCache` | `staleAfterFor` closures decide freshness; no way to say "ignore the cached entry" | Gains a `forceLive` flag threaded into the private `fetch()` (D-263) |
| `App\Service\Setlist\SetlistFmBudget::acquire(?float $waitOverrideSeconds)` | Breaker → daily counter → per-second token, fail-closed | **Unchanged.** The new caller consumes it like every other caller |
| `App\Entity\User::$roles` | *"populated exactly once, server-side, at registration"* — never mutated by app code | **Unchanged** (D-257). Entitlement is a new nullable timestamp column, not a role |
| `App\Security\Voter\EmailVerifiedVoter` | A state-flag voter over `User`, config-gated | The shape the new entitlement voter copies (D-258) |
| `App\Service\Admin\AuditLogger::log(User $actor, …)` | Takes any `User`; **no role check, no admin assumption in the signature** | **No signature change needed** (D-265). Every existing caller is a backoffice controller, but that is convention, not a constraint |
| `App\ApiResource\Me` | `{id, email, emailVerified, roles, createdAt}` | Gains one read-only boolean (D-269) |

---

### Load-bearing rules this feature does not reverse

| Rule | Source | How this design honours it |
|---|---|---|
| *setlist.fm responses are always cached; a cache miss is a budget decision* | `CLAUDE.md` | Forced-live results are still written through **both** cache tiers (AC-2.5). "Force live" skips the *freshness check*, never the *write* |
| *`SetlistGateway` is the only door* | `CLAUDE.md`, D-58 | The two new force-live methods live **on the gateway**. `SetlistGatewayIsOnlyDoorTest` passes unchanged, untouched (AC-11.4) |
| *The app cannot exceed its rate limit or its daily budget* | D-61, AC-7.3 | Every forced call calls `SetlistFmBudget::acquire()`. No override, no reserved lane, no second counter (D-254) |
| *A web request never queues on the rate limiter* | D-62 | The `POST` dispatches a Messenger message and returns. Zero outbound calls happen on the request thread (D-256, AC-3.1) |
| *A user-scoped resource returns 404, never 403* | `CLAUDE.md`, D-27 | `ConcertOwnerExtension` is not modified, not made role-aware, and gains no branch. The ownership check reuses `ConcertLocator` exactly as `StartGenerationProcessor` does (D-266) |
| *Setlist data is shared reference data, not user-scoped* | D-66 | The `Band` is still not owned. The **action** is gated by the caller having a concert with that band; the **data** stays shared, and a successful refresh — or a successful pick — benefits every user of that band (AC-1.6, AC-6.11) |
| *Once a `Band` carries an MBID, no code path may re-derive identity* | D-56 | The pick writes only where `setlistfmMbid` is `null` (D-270, AC-6.7). It is not a re-derivation; it is the first derivation, made by a human instead of by the exact-match rule |
| *A wrong shared disambiguation is visible and correctable in one place* | D-57, spec 09 R-4 | Unchanged. The operator's `correctMbid` + `clearSetlistCache` pair still corrects any band, whoever resolved it, and is still audited (D-273) |
| *Playlist generation degrades, it does not fail* | `CLAUDE.md` | A refused refresh changes nothing about the generation result the user is already looking at. It is additive, never a precondition |
| *Provider credentials never leave the secrets layer* | `CLAUDE.md` | No credential is read, written, rendered or logged anywhere in this change |
| *The backoffice edits behaviour, never credentials* | `CLAUDE.md` | The one new admin action grants an entitlement — account state, not a secret — audited, CSRF-validated, inside the 2FA-gated firewall |
| *The OpenAPI spec is the single source of truth* | `CLAUDE.md` | New operations are declared on an API Platform resource; **no endpoint list appears in any README** |

### Existing groundwork this design builds on, not around

- **`PlaylistGenerationJobResource` + `StartGenerationProcessor`** are the template for the whole
  request/response half: a `POST` that validates, dispatches to Messenger and returns without doing
  the expensive work; a `GET` that polls and carries `Retry-After`; `422` for "wrong state for this
  action"; and D-129's *"a second POST for the same live job returns the existing one, never a 409"* —
  copied verbatim as D-262.
- **`BuildPlaylistMessage` / `BuildPlaylistHandler`** are the async execution precedent. The new
  message is smaller and does strictly less.
- **`UserCrudController::confirmToggleActive()` / `performToggleActive()`**, and the just-merged
  `admin-set-email-verified` action, are the exact shape for granting the entitlement: GET
  confirmation with no side effect → CSRF-validated POST → mutate → flush → audit → redirect;
  re-render with an `error` and `422` on failure.
- **`AuditLogger`** is the single write path for `AuditLogEntry` (D-43). This feature must not break
  the property that *"did we audit this?"* is answerable by listing that class's callers.
- **`FreshnessEnvelope`** already carries `{source, fetchedAt, stale, reason, budgetResetAt}`. It is
  embedded verbatim rather than extended (D-261).
- **`symfony/lock`** already guards the nightly job and the cache stampede. The same component gives
  "at most one in-flight refresh per band" for free.
- **Redis day-counters with a short expiry** are the established home for setlist.fm telemetry
  (D-68). The cooldown, the per-user cap and the refresh state all live there (D-264).

---

## Goals

| Goal | Success looks like |
|---|---|
| A stuck band has a user-visible exit | An entitled user whose band is `unresolved`, `no_presence`, `ambiguous`, or `resolved`-but-stale can unstick it themselves, in seconds, without an operator and without waiting for the nightly job |
| Ambiguity is escapable without an operator | An entitled user shown several same-named bands picks the right one, the shared `Band` resolves, and its setlists arrive in the same interaction — the state the product has never had an automatic or user-facing exit from |
| Widening who may resolve a band does not widen what they may do | A user can only fill an empty identity, only from candidates the server produced, only once, only on their own concert's band — each enforced by a test, and every pick named in an audit entry |
| The exception does not become the rule | The unentitled path is byte-identically what it was before this branch — proven by a test asserting an unentitled caller gets `403` and **zero** outbound calls are attempted |
| The budget is not weakened | `SetlistFmBudget` is the same class with the same behaviour; a forced refresh is refused when the budget is spent, exactly like everything else. No test in spec 09's suite changes |
| One user cannot ruin the day for everyone | The arithmetic is bounded and written down (below): a single entitled user can spend at most `2 × SETLISTFM_REFRESH_NOW_DAILY_PER_USER` requests per day, and on-demand refresh in aggregate can never touch the last `SETLISTFM_REFRESH_NOW_BUDGET_RESERVE` share of the day |
| No web worker is held by a live call | Zero outbound setlist.fm requests are issued on the `POST` thread — proven by a test counting transport calls during the request |
| A refusal is actionable, not mysterious | Every refusal is one status code, one typed reason, and an instant to come back at |
| Every spend is attributable | Every trigger writes exactly one `AuditLogEntry` naming the actor, the band and the band's state at the time |
| The cost is visible before it bites | The backoffice setlist.fm panel shows on-demand triggers, refusals by reason, and the share of today's budget on-demand refresh consumed |

---

## User Stories

### US-1 — An entitled user triggers a refresh for a band on their own concert

> As an **entitled user whose concert shows "no setlist found"**, I want to ask the app to look again
> right now, so that I am not stuck waiting for a nightly job or an operator.

**Acceptance criteria**

- **AC-1.1** A `POST` operation on the band accepts an empty body and returns **`202 Accepted`** with
  the refresh's state when the trigger is accepted.
- **AC-1.2** The operation requires `IS_AUTHENTICATED_FULLY` **and** the new entitlement attribute
  (US-7). An authenticated but unentitled caller gets `403` and nothing is queued, nothing is
  counted, and no outbound call is attempted.
- **AC-1.3** An unknown band id returns `404`. A band that exists but appears on no concert owned by
  the caller returns **`422`** with reason `band_not_on_your_concerts` (D-266) — the band's existence
  is already discoverable through the pre-existing authenticated read operations, so a `404` here
  would hide nothing and would misreport the actual problem.
- **AC-1.4** The ownership check reuses `ConcertLocator`'s owner-filtered query path; no new query
  extension is introduced and `ConcertOwnerExtension` is not modified.
- **AC-1.5** A second `POST` while a refresh for the same band is already in flight returns **`200`**
  with that in-flight refresh — never `409`, never a second job (D-262, mirroring D-129).
- **AC-1.6** The refresh operates on the shared `Band`. A successful refresh benefits every user who
  has a concert with that band; nothing about it is copied per user.

### US-2 — The refresh actually re-attempts a stuck identity

> As **the same user**, I want the refresh to genuinely re-check a band the app has already given up
> on, so that "try again" means something.

**Acceptance criteria**

- **AC-2.1** A new `BandIdentityResolver::forceResolve(Band $band, \DateTimeImmutable $now)` resets an
  `unresolved`, `ambiguous` or `no_presence` band via the existing `Band::resetResolution()` and
  delegates to `ensureResolved()`, so classification logic exists in exactly one place.
- **AC-2.2** `forceResolve()` on a band already in state `resolved` **does not re-derive its
  identity** — it returns the resolved outcome immediately (D-56 stands, D-263). Correcting a wrong
  MBID remains the operator's audited action.
- **AC-2.3** `ensureResolved()`'s existing early-return guard is **not** modified, relaxed or made
  conditional. `forceResolve()` is a separate entry point.
- **AC-2.4** After a successful resolution, the refresh fetches the band's setlist index **page 1**
  live, ignoring that entry's `staleAfter` — a forced fetch, not a "fetch if stale" one.
- **AC-2.5** A forced-live response is written through **both** cache tiers exactly as an ordinary
  miss is (`CLAUDE.md`: always cached). The next reader gets the fresh data for free.
- **AC-2.6** Force-live is accepted **only** for the two volatile cache classes — `artist.search` and
  `artist.setlists` page 1. It is rejected for `setlist.get` and `artist.setlists` page ≥ 2, which
  D-59 defines as immutable; a forced re-fetch of immutable history is budget spent on a guaranteed
  identical answer. Enforced by a test.
- **AC-2.7** A refresh issues **at most 2** outbound requests (one search, one index page). Asserted
  by a test with a counting transport.
- **AC-2.8** A static test asserts `forceResolve()`, `resolveAmbiguousChoice()` and the gateway's
  force-live methods are called from the refresh handler and the two refresh processors only — no read
  path, no state provider, no other service (the same structural-enforcement move as D-58).
- **AC-2.9** `resolveAmbiguousChoice()` makes **no** outbound call of any kind: the MBID and the
  canonical name both come from a candidate the search already produced and the refresh record still
  holds. Asserted with a counting transport (D-279).

### US-3 — The trigger is instant; the work is not on the request thread

> As the **product owner**, I want the `POST` to return immediately, so that a slow setlist.fm cannot
> hold a FrankenPHP worker hostage the way D-62 warned about.

**Acceptance criteria**

- **AC-3.1** **Zero** outbound setlist.fm requests are issued during the `POST` request cycle —
  asserted by a test that counts transport calls across the request.
- **AC-3.2** The work runs in a Messenger handler, alongside `BuildPlaylistHandler`, from a new
  message carrying the band id, the actor's user id and the trigger instant.
- **AC-3.3** The handler consumes `SetlistFmBudget::acquire()` with a bounded wait override
  (`SETLISTFM_REFRESH_NOW_TOKEN_WAIT`, default `3`) — longer than a web request's 1s because it is
  not a web request, far shorter than the nightly job's patience because a human is watching.
- **AC-3.4** A `GET` on the same URI returns the current or most recent refresh for that band:
  `state` (`queued` | `running` | `succeeded` | `failed`), `requestedAt`, `finishedAt`, the band's
  resolution state before and after, the embedded freshness envelope, and `cooldownUntil`.
- **AC-3.5** While `state` is `queued` or `running`, the `GET` carries a `Retry-After` header; once
  terminal it does not — the same polling contract `PlaylistGenerationJobResource` already
  established.
- **AC-3.6** A `GET` for a band that has never been refreshed returns `200` with `state: null` and
  the band's current resolution state — not a `404`.
- **AC-3.7** A handler failure (upstream error, unexpected exception) produces `state: failed` with
  the freshness envelope's `reason`, and never a poisoned queue: the message is not retried
  indefinitely, because a retry is another budget unit.

### US-4 — A refusal says why, and when to come back

> As the **user**, I want to be told plainly that I have to wait, and until when, so that I do not
> hammer the button.

**Acceptance criteria**

- **AC-4.1** Every refusal — cooldown, per-user cap, budget reserve, budget exhausted, rate limited,
  breaker open — is **`429 Too Many Requests`** with a `Retry-After` header and a typed
  `refusedReason` in the body (D-260).
- **AC-4.2** `refusedReason` is one of: `cooldown_active`, `daily_limit_reached`, `budget_reserved`,
  `budget_exhausted`, `rate_limited`, `upstream_unavailable`.
- **AC-4.3** The body also carries `retryAfterAt` as an absolute UTC instant, so the client can say
  "16:40" rather than "in a while".
- **AC-4.4** `FreshnessEnvelope`'s `reason` enum is **not** extended with the three throttle reasons
  (D-261): they live in `refusedReason` on the refresh output. The envelope keeps describing data
  freshness and nothing else, and every existing read response is unchanged.
- **AC-4.5** A test asserts each of the six reasons is reachable and distinguishable by field value
  alone.
- **AC-4.7** The pick's two refusals — `mbid_not_a_candidate` and `band_already_resolved` — are
  **`422`, not `429`**, and are not members of `refusedReason`. They are not "come back later": no
  amount of waiting makes a non-candidate MBID valid, and a resolved band is *finished*, not busy.
  `422` is this codebase's status for "the right actor, the wrong state for this action" (D-266,
  `StartGenerationProcessor`), and `Retry-After` would be a lie on both.
- **AC-4.6** No setlist.fm error body, URL or header is ever forwarded to the client (AC-9.7 of spec
  09 still holds).

### US-5 — One entitled user cannot spend the day

> As the **product owner**, I want the exception to be bounded by arithmetic I can read, so that
> granting an entitlement is not granting a denial-of-service.

**Acceptance criteria**

- **AC-5.1** Three throttles are checked **before** `SetlistFmBudget`, in this order, each with its
  own refusal reason:
  1. **per-band cooldown** — no second refresh of the same band within
     `SETLISTFM_REFRESH_NOW_COOLDOWN` (default `3600`s), across *all* users, because the band is
     shared and a second look costs the same whoever asks;
  2. **per-user daily cap** — `SETLISTFM_REFRESH_NOW_DAILY_PER_USER` (default `5`) accepted triggers
     per UTC day;
  3. **application budget reserve** — refused when today's remaining budget is below
     `SETLISTFM_REFRESH_NOW_BUDGET_RESERVE` (default `0.10`) of `SETLISTFM_DAILY_BUDGET`.
- **AC-5.2** Only an **accepted** trigger consumes cooldown and daily-cap allowance. A refusal costs
  the user nothing — the same principle as AC-7.7 counting *requests issued*, not attempts.
- **AC-5.3** The counters live in Redis and **fail closed**: a Redis error refuses the trigger with
  `upstream_unavailable`, matching `SetlistFmBudget`'s posture (AC-7.6, D-61). A throttle that fails
  open is not a throttle.
- **AC-5.4** The reserve is a floor on *on-demand* spending only. It does **not** reserve budget for
  on-demand refresh, and it never lets a refresh through when `SetlistFmBudget` says no.
- **AC-5.5** A test drives one user to the daily cap and asserts the next trigger is refused with
  `daily_limit_reached`; another drives the daily budget to within the reserve and asserts
  `budget_reserved`.
- **AC-5.6** A concurrency test issues simultaneous triggers for the same band from two users and
  asserts exactly one is accepted (D-262's lock) and at most one budget unit pair is spent.

**The arithmetic, worked explicitly**

| Quantity | Value |
|---|---|
| Requests per accepted refresh | ≤ 2 (one `artist.search`, one index page) — AC-2.7 |
| Ceiling for one entitled user, one day | 5 × 2 = **10** requests = 0.7 % of 1,440 |
| Reserve floor | 10 % of 1,440 = **144** requests on-demand refresh can never touch |
| Nightly job's share (unchanged, D-65/AC-10.3) | 25 % = 360 requests |
| Theoretical worst case for on-demand in aggregate | 1,440 − 144 = 1,296 requests ⇒ **648 accepted refreshes** ⇒ ~130 entitled users at full cap |

The per-user cap bounds the *individual*; the reserve bounds the *population*. Neither alone is
enough, which is why both exist. At MVP scale (a handful of entitled users) the cap dominates and the
reserve never fires; the reserve is the thing that keeps the design honest if entitlement is ever
granted broadly. All four numbers are env-configurable and are expected to be tuned from the
dashboard (US-9), which is why the dashboard is in the same branch.

### US-6 — Ambiguity is reported, and the user resolves it

> As the **user**, I want to be shown the several bands the app found with this name and to say which
> one is mine, so that I get my setlists instead of an explanation.

**Acceptance criteria**

*Reporting*

- **AC-6.1** A refresh that ends `ambiguous` reports `state: succeeded` with the band's resolution
  state `ambiguous` — the refresh *worked*; the outcome is that the band is genuinely ambiguous.
- **AC-6.2** The response carries the candidate list (the shape `BandSearchCandidateOutput` already
  defines: `mbid`, `name`, `sortName`, `disambiguation`), in the order setlist.fm returned it.
- **AC-6.3** The cooldown applies to an `ambiguous` outcome exactly as to any other — re-running a
  deterministic search is the definition of a wasted budget unit. **Picking** is the way forward from
  `ambiguous`, and it is not a retry (AC-6.10).
- **AC-6.4** The candidate list is stored on the refresh's Redis record alongside its state (D-264),
  so a later pick can be validated against the exact set the user was shown (D-271).

*Resolving*

- **AC-6.5** A **separate** `POST` operation accepts `{"selectedMbid": "…"}` and resolves the band to
  that MBID (D-278). The trigger operation's body stays empty and its meaning stays single.
- **AC-6.6** The submitted MBID is accepted **only** if it appears in the candidate list held on that
  band's current refresh record. Anything else — an MBID from another band's search, a well-formed
  MBID that was never proposed, an expired record — is **`422`** with reason `mbid_not_a_candidate`.
  No free-text MBID is ever accepted from a user (D-271).
- **AC-6.7** Any returned candidate is selectable, not only ones whose name normalizes exactly to the
  band's (D-272). The auto-resolver's exact-match conservatism (spec 09 AC-2.3) governs what the
  *machine* may decide unaided; a human reading `disambiguation` text is the extra information that
  justifies the wider set — and the `0 exact matches among several` shape is precisely the Boikot
  case this amendment exists to fix.
- **AC-6.8** The pick is refused with **`422`** and reason `band_already_resolved` when the band
  already holds an MBID — including a band resolved by another user seconds earlier. A band is
  user-resolvable exactly once (D-276); the operator's correction is the only later write (D-273).
- **AC-6.9** The pick requires the same two gates as the trigger: the `CAN_REFRESH_SETLIST_NOW`
  attribute (`403` otherwise) and a concert of the caller's featuring that band (`422`,
  `band_not_on_your_concerts`, per D-266). Neither gate is duplicated inline; both reuse the trigger's
  code path.
- **AC-6.10** The pick itself spends **no** setlist.fm budget and passes **none** of the three
  throttles — it is a database write over data already fetched (D-275). It does, however, complete as
  a one-request setlist fetch (AC-6.12), and *that* passes the budget gate.
- **AC-6.11** A successful pick writes the chosen MBID and the candidate's setlist.fm canonical name
  through the existing `Band::resolveTo()` (D-279), leaving `Band::$name` — whatever the first
  creator typed — untouched, exactly as spec 09's AC-1.3 requires. The band is now `resolved` for
  **every** user of it.
- **AC-6.12** The pick returns **`202`** and completes as a refresh: the same Messenger message,
  with identity already known, fetching only the setlist index page — **at most 1** outbound request,
  asserted by a test. It counts against the actor's daily cap and passes `SetlistFmBudget`, and it is
  **exempt from the per-band cooldown**, because the cooldown exists to refuse a deterministically
  identical question and the band's identity has just changed (D-277).
- **AC-6.13** The client polls the same `GET` for the outcome, honouring `Retry-After` — the pick
  reuses the refresh's state machine rather than adding a second one.
- **AC-6.14** A concurrency test has two users pick different candidates for the same band
  simultaneously and asserts exactly one write lands, the loser receives `422`
  `band_already_resolved`, and the band ends on the winner's MBID with one audit entry naming them.

### US-7 — Entitlement is granted deliberately, in the backoffice, audited

> As the **product owner**, I want to hand this capability to specific users and to be able to take
> it back, without inventing a pricing model and without making `User.roles` writable.

**Acceptance criteria**

- **AC-7.1** `User` gains one nullable timestamp column, `instantRefreshGrantedAt` — `null` means not
  entitled (D-257). `User::$roles` is **not** mutated by any code in this branch, and the entity
  docblock's "populated exactly once at registration" claim stays true.
- **AC-7.2** A new voter exposes the attribute `CAN_REFRESH_SETLIST_NOW` over a `User` subject,
  copying `EmailVerifiedVoter`'s state-flag shape — not `ConcertVoter`'s ownership shape (D-258).
- **AC-7.3** The voter is the **only** place the entitlement is read. No processor, handler,
  controller or template tests `instantRefreshGrantedAt` directly — so prompt 22 replaces one class
  (D-267). Enforced by a static test.
- **AC-7.4** `UserCrudController` gains a two-way grant/revoke action following the established
  confirm → CSRF-validated POST → mutate → flush → audit → redirect shape, inside the 2FA-gated,
  IP-allowlisted admin firewall.
- **AC-7.5** Both directions are audited through `AuditLogger` with action
  `grant_instant_refresh` / `revoke_instant_refresh`.
- **AC-7.6** Revoking is immediate: the next trigger from that user is `403`. Nothing already queued
  is cancelled — one refresh in flight is not worth a cancellation path.
- **AC-7.7** No pricing, tier name, paywall or upgrade prompt appears anywhere in this branch —
  prompt 22's constraint, adopted here in advance.

### US-8 — Every trigger is attributable

> As the **product owner**, I want to be able to answer "who spent this budget" from a durable record.

**Acceptance criteria**

- **AC-8.1** Every **accepted** trigger writes exactly one `AuditLogEntry` through `AuditLogger`:
  action `trigger_setlist_refresh`, `subjectType` `Band`, `subjectId` the band id, `field`
  `setlistfmResolutionState`, `oldValue` the state at trigger time, `newValue` `requested`.
- **AC-8.2** The entry is written **in the request thread**, not in the Messenger handler, because
  `AuditLogger` reads the client IP and User-Agent off `RequestStack` — a worker has no request, and
  would record `0.0.0.0` (D-265).
- **AC-8.3** `AuditLogger::log()`'s signature is **unchanged**. It already accepts any `User` and
  performs no role check; that all existing callers happen to be backoffice controllers is
  convention, not a constraint. A non-admin actor is recorded correctly, with the same digested
  `actorLabel`.
- **AC-8.4** Refusals are **not** audited — they are Redis counters and a log line (US-9). Auditing
  refusals would let an unentitled caller write rows to an audit table, which is a write primitive
  handed to an attacker.
- **AC-8.5** The refresh's **outcome** is not a second audit entry. The audit log answers *who asked
  for a budget spend*; the outcome lives in the refresh state, the `setlistfmLogger` and the
  dashboard.
- **AC-8.6** A test asserts the backoffice audit list renders an entry whose actor is an ordinary
  user without error or admin-specific assumption.
- **AC-8.7** Every successful **pick** writes exactly one further `AuditLogEntry` through
  `AuditLogger`: action **`choose_band_mbid`** — deliberately *not* the operator's
  `correct_band_mbid` (D-274) — `subjectType` `Band`, `subjectId` the band id, `field`
  `setlistfmMbid`, `oldValue` the band's resolution state at the time (`ambiguous`), `newValue` the
  chosen MBID. Two distinct action names means *"which band identities were chosen by users rather
  than operators?"* is a `WHERE action = …`, not an inference from the actor's roles.
- **AC-8.8** A refused pick (`mbid_not_a_candidate`, `band_already_resolved`, either gate) writes
  **no** audit entry — AC-8.4's rule, unchanged: a refusal must never be a write primitive.
- **AC-8.9** A band resolved by a user carries no marker on the `Band` row itself — no
  `resolvedByUser` column, no provenance field. The audit log is the record of who chose (D-274), and
  a band's identity is a band's identity regardless of who supplied it. Enforced by the migration
  containing exactly the one column D-257 specifies.

### US-9 — The operator can see what the exception costs

> As the **product owner**, I want on-demand refresh visible on the same panel as the budget it
> spends, so that I find out it is a problem before my users do.

**Acceptance criteria**

- **AC-9.1** The existing setlist.fm dashboard panel gains: triggers accepted today, refusals today
  broken down by reason, and requests spent by on-demand refresh today as an absolute number and as a
  share of `SETLISTFM_DAILY_BUDGET`.
- **AC-9.2** The counters are per-day Redis keys with a 7-day expiry, consistent with D-68 — no table,
  no row per trigger.
- **AC-9.3** The panel reads them uncached (D-53, AC-11.7).
- **AC-9.4** The panel shows the number of currently entitled users, so "who can do this" is one
  glance rather than a query.
- **AC-9.5** The panel also shows **user-made band resolutions today** (the `choose_band_mbid` count).
  It is the one figure that tells an operator the blast radius of D-270 is being exercised, and it is
  the number to watch in the month after launch: a spike means either the feature is working or the
  auto-resolver is failing, and both are worth knowing.

### US-10 — The client offers the action where the failure is visible

> As the **user**, I want the "try again now" action to be on the screen that just told me it could
> not find a setlist.

**Acceptance criteria**

- **AC-10.1** `Me` gains one read-only boolean, `canRefreshSetlistNow`, derived from the voter — not
  from the raw column, and never writable (D-269).
- **AC-10.2** The playlist result screen shows the action **only** when the user is entitled **and**
  the per-band `noSetlistCause` is one of the causes a refresh can plausibly help.
- **AC-10.3** The action is disabled with the reason and the return time while a cooldown is active,
  rather than hidden — a hidden control teaches nothing.
- **AC-10.4** While the refresh is `queued`/`running`, the screen polls the `GET` honouring
  `Retry-After`, reusing the existing polling helper rather than inventing a second one.
- **AC-10.5** Each of the six refusal reasons has distinct, human copy naming the return time.
- **AC-10.6** An `ambiguous` outcome shows the candidates — name, and `disambiguation` where
  setlist.fm gives one, because that text is usually the only thing distinguishing them — and asks the
  user to choose one. It does **not** offer another retry, which cannot help (AC-6.3).
- **AC-10.7** Client types are regenerated from the OpenAPI document before the screen is wired up;
  no request or response shape is hand-declared (`CLAUDE.md`).
- **AC-10.8** Choosing is a **deliberate, confirmed** action, not a tap on a list row: the user
  confirms the specific band before the request is sent, and the confirmation says plainly that the
  choice applies to this band for everyone and can afterwards only be changed by support. A shared,
  one-shot write deserves the friction (D-276, D-280).
- **AC-10.9** After a successful pick the screen keeps polling the same refresh `GET` (AC-6.13) and
  shows the resulting setlist state — the user sees the outcome of their choice, not a dead end that
  asks them to navigate away and come back.
- **AC-10.10** `mbid_not_a_candidate` and `band_already_resolved` have their own copy. The second is
  a normal outcome, not an error: another user resolved this band first, and the correct message says
  the band is now resolved and offers to show the setlists.

### US-11 — Green in CI, without calling setlist.fm

**Acceptance criteria**

- **AC-11.1** Every test uses the mocked transport and the existing spec-09 fixtures; the default
  suite makes zero outbound calls (`docs/architecture.md` D-2).
- **AC-11.2** Tests run against real Redis and real PostgreSQL from `compose.yaml` — the cooldown, the
  per-user cap and the lock are exactly the behaviours a double would fake away (AC-13.5).
- **AC-11.3** New fixtures, if any, are added to the existing recorded set; no new live capture is
  required — the AC-13.4 set already covers multi-candidate, single-match and empty searches.
- **AC-11.4** `SetlistGatewayIsOnlyDoorTest` passes **unmodified**. If it needs a change, the design
  is wrong.
- **AC-11.5** A regression test asserts an unentitled user's full request cycle attempts zero
  outbound calls — for the trigger operation **and** the pick operation.
- **AC-11.6** The multi-candidate fixture from AC-13.4 of spec 09 drives the whole pick path
  end to end: ambiguous refresh → candidate list → pick → resolved band → index fetch. No new live
  capture is needed for the amendment.
- **AC-11.7** A test asserts a pick whose MBID is well-formed but absent from the stored candidate
  set is refused, and that the band's row is byte-identical afterwards.

---

## Technical Approach

### Backend (`backend/`)

| Area | Work |
|---|---|
| Entity | `User.instantRefreshGrantedAt` (`?\DateTimeImmutable`, nullable). One migration, one column. No other entity changes |
| Security | `App\Security\Voter\InstantRefreshVoter` — attribute `CAN_REFRESH_SETLIST_NOW`, subject `User`, modelled on `EmailVerifiedVoter` |
| Services | `App\Service\Setlist\SetlistRefreshCoordinator` — the three throttles, the per-band lock, the Redis refresh-state record (**now also holding the candidate list**, AC-6.4), the telemetry counters. `BandIdentityResolver::forceResolve()` **and `resolveAmbiguousChoice()`**. `SetlistGateway::refreshArtistSearch()` / `refreshArtistSetlistsPage()` and a `forceLive` flag through `SetlistCache::fetch()` |
| Messenger | `RefreshBandSetlistsMessage` + `RefreshBandSetlistsHandler`, alongside the playlist pipeline's pair. One message serves both entry points; a flag on it says whether identity is already settled, so the handler skips the search (AC-6.12) |
| API | `App\ApiResource\Setlist\BandSetlistRefreshResource` — `POST` + `GET` on one URI, **plus a second `POST` on a `…/resolution` sub-path taking `{selectedMbid}`** (D-278); `BandSetlistRefreshOutput` embedding `FreshnessEnvelope` and reusing `BandSearchCandidateOutput`; `TriggerSetlistRefreshProcessor`, **`ResolveBandIdentityProcessor`**, `BandSetlistRefreshProvider`. `Me` gains `canRefreshSetlistNow` |
| Admin | Grant/revoke action on `UserCrudController` + confirmation template; four new figures on the setlist.fm dashboard panel (AC-9.1, AC-9.5). `BandCrudController` is **not** touched |
| Migration | One: a single nullable timestamptz column on `users` |

### Frontend (`frontend/`)

Regenerated API types; one conditional action plus its polling and its six refusal messages on the
existing playlist result screen, and — for the `ambiguous` outcome — a candidate list with a
confirmation step (AC-10.6, AC-10.8). No new route, no new screen, no design-system addition: the
candidate list is rendered inline on the result screen, where the failure it explains is displayed.

### New environment variables

| Variable | Secret | Default | Purpose |
|---|---|---|---|
| `SETLISTFM_REFRESH_NOW_COOLDOWN` | no | `3600` | Seconds before the same band may be refreshed again, across all users (AC-5.1) |
| `SETLISTFM_REFRESH_NOW_DAILY_PER_USER` | no | `5` | Accepted triggers per user per UTC day (AC-5.1) |
| `SETLISTFM_REFRESH_NOW_BUDGET_RESERVE` | no | `0.10` | Share of the daily budget on-demand refresh may never touch (AC-5.1) |
| `SETLISTFM_REFRESH_NOW_TOKEN_WAIT` | no | `3` | Seconds the refresh handler waits for a rate token (AC-3.3) |

All four go into `docs/env-vars.md` **and** `backend/.env.example` in the same commit. Both files or
neither.

### Decisions

Numbered from **D-254**; the previous spec (`2026-08-27-admin-set-email-verified`) ended at D-253.
**D-254** and **D-255** are also written into `docs/specs/2026-08-22-setlistfm-integration.md`, since
they amend that document's D-65 and D-67; **D-270 – D-272** are written into it too, since they widen
D-57's set of permitted choosers. **D-268 is superseded by D-270 – D-280** and kept in place with a
note — the same add-a-note-never-delete rule this spec applies to spec 09, applied to itself.

**D-254 — The on-demand exception exists, and it is paid for with throttles, not with a quota.**
D-65 rejected on-demand checks with a specific argument: *they scale with traffic*. That argument is
correct and this design answers it directly rather than waving it away. An on-demand refresh here
does not scale with traffic — it scales with `min(entitled users × 5, remaining budget above the
reserve)`, both of which are configuration. It is not speculative (a human asked for it by name), not
repeated (a per-band cooldown makes the second ask free of charge and free of budget), and not
privileged (it consumes `SetlistFmBudget::acquire()` like everything else, and is refused when the
budget is spent). The rejected alternative — a separate quota carve-out for entitled users — is worse
in exactly the way D-61 warns about: two counters is one counter someone forgets. Cost accepted: on a
busy day, an entitled user's refresh can be the request that exhausts the budget for an unentitled
one. The reserve (D-259) bounds how much of the day that can be; it does not eliminate it.

**D-255 — D-65 and D-67 are narrowed to the default path; the backoffice still gets no button.**
D-67's "notably absent: a refresh this band now button" stays absent **from `/admin`**. Operators
already have the two audited writes that matter (MBID correction, cache clear), and an operator who
wants fresher data can run `app:setlist:refresh`. Adding a backoffice refresh button would be a third
write with no user need behind it. D-65's nightly-only rule remains the rule for every unentitled
user, which is every user until someone is deliberately granted the flag. What changes is a single
sentence's scope — *"On-demand per-user checks are rejected outright"* becomes *"…rejected outright on
the default path"* — not the reasoning underneath it.

**D-256 — The action is asynchronous. "Instant" is the trigger, not the completion.**
D-62 exists because a live setlist.fm call can hold a FrankenPHP worker. Worked through: up to 3s
waiting for a rate token, plus a 5s HTTP timeout, plus up to 2 jittered retries, times two calls
(search then index) — a worst case comfortably past 20 seconds of held worker, for a request whose
useful response is "we started looking". So the `POST` validates, throttles, audits, dispatches and
returns `202`; a Messenger handler does the work; the client polls the `GET` with `Retry-After`, the
contract it already implements for playlist generation. The rejected alternative — synchronous with a
tight timeout — would either lie (return success before the work finished) or hold the worker, and it
would put the product's most dangerous resource on the request thread. The honest cost: the feature
is called "instant" and is not instantaneous. It is typically 1–3 seconds end to end, which is what a
user means by instant, and it degrades to "still working" instead of to a hung request.

**D-257 — Entitlement is a nullable grant timestamp on `User`, not a mutated `roles` array.**
`User::$roles` is documented as *"populated exactly once, server-side, at registration"*, and that
sentence is a security property: nothing in the app can escalate a user, because nothing in the app
writes roles. Adding the first writer — even a narrow, audited one — creates the seam a later generic
"edit roles" screen slides into, and that screen grants `ROLE_ADMIN`. A nullable timestamp mirrors
`emailVerifiedAt` exactly, is the idiom this codebase already uses for a grantable flag, and records
*when* the grant happened, which prompt 22 will want. Cost accepted: one migration, and one new field
on `Me` (`roles` already ships to the client, so a role would have propagated for free). One column
and one field is a small price for keeping "roles are immutable" literally true.

**D-258 — The gate is a state-flag voter, and it is the only reader of the flag.**
`InstantRefreshVoter` copies `EmailVerifiedVoter`: an attribute over a `User` subject, no ownership
semantics, one boolean question. Nothing else in the codebase reads `instantRefreshGrantedAt`
(AC-7.3, statically enforced), so when prompt 22 introduces `UserEntitlement`/`EntitlementPlan`,
migrating this capability is a rewrite of one method body and a dropped column — not a hunt through
processors. The rejected alternative, checking the column inline in the processor, is one line
shorter today and a refactor later.

**D-259 — Three throttles, all in front of the budget gate, all fail-closed.**
Per-band cooldown, per-user daily cap, application budget reserve. Each answers a different failure
mode and none substitutes for the others: the cooldown stops the same question being asked twice (and
is band-scoped rather than user-scoped precisely because the band is shared — the second user's ask
costs the same units as the first's); the cap bounds one enthusiastic individual; the reserve bounds
the entitled population in aggregate and is the only one that still works if entitlement is granted
broadly. They sit **before** `SetlistFmBudget` so a refused trigger never touches the token bucket at
all. They fail closed on a Redis error, because a throttle that fails open is not a throttle (D-61's
posture, adopted verbatim). Cost accepted: four numbers to tune, and a user can be refused by a
throttle while the budget is in fact plentiful. That is the intended direction of error.

**D-260 — Every refusal is `429` with `Retry-After`, not a mix of statuses.**
All six reasons reduce to the same sentence: *not now, come back at T*. Cooldown until T; cap until
UTC midnight; reserve and exhaustion until the budget reset; rate limit in a second; breaker at the
end of its cooldown. Giving them one status gives the client one branch, and `Retry-After` is already
this project's vocabulary for it. D-63's "degradation is a field, not a status" is not contradicted:
that rule governs **reads**, where a `200` is honest because there is a cached answer to return.
Here there is no answer at all — the requested action did not happen — and dressing that as `200`
would make the client parse success to discover failure.

**D-261 — `FreshnessEnvelope` is embedded verbatim, never extended.**
The refresh output carries a real `FreshnessEnvelope` describing the data, and a separate
`refusedReason` describing the throttle. The rejected alternative — adding `cooldown_active`,
`daily_limit_reached` and `budget_reserved` to the envelope's `reason` enum — would add three values
that are unreachable in every other place the envelope appears, which is every setlist-bearing read
in the product. An enum whose values are impossible in 90 % of its uses stops documenting anything.
Reusing the *shape* without corrupting the *vocabulary* is the point.

**D-262 — At most one in-flight refresh per band; a second trigger returns the first.**
A `symfony/lock` keyed on the band, and a second `POST` returns `200` with the in-flight refresh —
exactly D-129's ruling for concurrent generation starts, and for the same reason: two jobs for one
question is a double spend, and a `409` makes the client handle an error for something that is not
one.

**D-263 — Forcing is surgical: never a resolved identity, never immutable data.**
`forceResolve()` refuses to re-derive a `resolved` band (D-56: *once a `Band` carries an MBID, no code
path may re-derive identity*), and force-live is accepted only for `artist.search` and
`artist.setlists` page 1 (D-59: past setlists are immutable, so re-fetching them is budget spent on a
guaranteed identical answer). What "force" actually means is narrow and worth stating plainly: it
skips the *guard clause* on stuck states and the *freshness short-circuit* on volatile entries. It
does not skip the cache write, the lock, the budget gate, or the breaker.

**D-264 — The refresh's state lives in Redis; its durable record is the audit entry.**
A `SetlistRefreshRequest` table would be a row per trigger, expiring in practical usefulness within
minutes, to serve a poll that lasts seconds — D-68's argument about metrics, applied to coordination
state. So the state, the cooldown and the counters are Redis keys with short expiries, and the thing
that must survive (who asked for a budget spend, and when) is the audit entry, which is already
durable and already indexed for the backoffice. Cost accepted: a Redis flush loses in-flight refresh
state, which self-heals within one cooldown window, and loses the cooldown itself — briefly allowing
a repeat spend. Acceptable against a table.

**D-265 — The audit entry is written in the request thread, and `AuditLogger` needs no change.**
`AuditLogger::log()` takes a `User` and performs no role check; the class is admin-only by convention,
not by signature, so a non-admin actor needs no widening. But it reads the client IP and User-Agent
from `RequestStack`, so writing the entry from the Messenger handler would silently record
`0.0.0.0` — the entry goes in the processor, before dispatch. It records the **trigger**, not the
outcome: the audit log answers the security question ("who spent budget"), and the operational
question ("what did it find") is served by the refresh state and the dashboard. Refusals are
deliberately not audited (AC-8.4) — auditing them hands an unauthenticated-adjacent caller a way to
write rows into an audit table.

**D-266 — "This band is not on any of your concerts" is `422`, not `403` and not `404`.**
The 404-not-403 rule (D-27) exists to stop an id's *existence* leaking. Here the band's existence
already leaks legitimately: `GET /api/bands/{id}/setlists` is authenticated-but-not-owner-filtered by
D-66, because bands are shared reference data. So a `404` would conceal nothing and would misreport
the situation as "no such band". `403` is wrong for the opposite reason — the caller *is* permitted
to do this, on a different band. `422` is what this codebase already uses for "the right actor, the
wrong state for this action" (`StartGenerationProcessor`'s 422s). `ConcertOwnerExtension` is not
touched, not made role-aware, and gains no branch.

**D-267 — This spec pre-dates prompt 22 and constrains it; it does not depend on it.**
Prompt 22 (entitlement and quota seam) will build `EntitlementPlan`, `UserEntitlement` and
`QuotaService`, and its own out-of-scope list forbids tier names — all of which this spec respects.
Waiting for it would block a support fix on a large, unscheduled piece of infrastructure. So this
feature ships the **narrowest possible** version — one boolean-shaped grant, one voter, one per-user
counter — and hands prompt 22 three obligations, recorded here so they are not rediscovered:
(1) absorb `instantRefreshGrantedAt` into `UserEntitlement` and drop the column;
(2) replace `InstantRefreshVoter`'s body with a plan lookup, changing no caller (AC-7.3 is what makes
this true); (3) supersede `SETLISTFM_REFRESH_NOW_DAILY_PER_USER` with a plan limit. This spec
**narrows** prompt 22; it does not duplicate it, because there is no plan, no tier and no pricing
here.

**D-268 — Ambiguity is reported to the user, never resolved by them.**
> **Superseded on 2026-08-27, before implementation, by D-270 – D-280.** This decision was written as
> a recommendation attached to Open Question 1, and the question was answered the other way: the user
> **may** resolve the ambiguity. The reasoning below is kept verbatim because it is still the correct
> statement of the risk — D-270 – D-280 answer it rather than dismiss it, and the safeguards they
> impose (candidate-set-only, vacancy-only, once-only) exist precisely because this paragraph is
> right about what D-57 makes a pick mean. What is no longer true is its conclusion, and its last
> sentence: the Boikot case is now fixed, not merely explained.

D-57 stores the disambiguation choice on the **shared** `Band`: one user's pick becomes every user's
setlists. Today exactly one class of actor can make that pick — an operator, audited, in a 2FA-gated
session — and R-4 accepted the blast radius on that basis. Extending it to any entitled user is a
different risk with a different mitigation story and belongs in its own spec (Open Question 1). So
the refresh reports `ambiguous` with the candidate list, read-only, and the client says so plainly.
The uncomfortable consequence, stated rather than buried: the reported Boikot case, if it is
`ambiguous` as the investigation suspects, is **not** fixed by this feature — it is only explained by
it. Explaining it is still worth more than the silence users get today.

**D-269 — The client surface is one conditional action on an existing screen.**
No new route, no new screen, no design-system component. `Me` gains one derived boolean — derived from
the voter, so the client and the server can never disagree about entitlement. Shipping the API
without any surface would violate `CLAUDE.md`'s *one feature, one spec, one branch* and would leave
the capability reachable only by hand-crafted requests; shipping a new screen would be scope the
failure does not justify. The action belongs where the failure is displayed.

---

#### D-270 – D-280 — the user-side disambiguation pick *(amendment, 2026-08-27)*

These eleven decisions replace D-268. They exist because the alternative to a user picking is not
"an operator picks" — at MVP there is one operator, who is the developer, and every ambiguous band is
a support ticket. It is "nobody picks, and the band stays broken", which is the state the
investigation found.

**D-270 — A user may fill an empty band identity; they may never overwrite one.**
This is the single line that separates a user's power from an operator's, and everything else follows
from it. `resolveAmbiguousChoice()` writes only when `Band::$setlistfmMbid` is `null` — states
`ambiguous`, `unresolved` and `no_presence`. Three consequences make this the right cut rather than
an arbitrary one. First, **D-56 survives untouched**: "once a `Band` carries an MBID, no code path
may re-derive identity" is about re-derivation, and there is nothing to re-derive when the field is
empty — the pick is the *first* derivation, made by a human where the exact-match rule declined to
guess. Second, **nothing is orphaned**: spec 09's AC-2.6 pairs an operator's MBID correction with
clearing the band's cached setlist associations, because a correction points an existing body of
cached data at a different artist. A band with no MBID has no such data by construction, so the
user's write needs no cache-clear, no purge, no second audited action — a materially smaller
operation than the operator's, not the same one handed to more people. Third, it makes the wrong
outcome *bounded*: the worst a pick can do is give a band setlists it should not have; it can never
take away setlists a band correctly had. Rejected alternative: letting a user re-pick a band they
believe is wrong. That is the operator's `correctMbid`, it requires the cache-clear, and it turns a
one-shot into a contest between users over a shared row.

**D-271 — The user chooses from a server-produced candidate set; a free-text MBID is never accepted.**
The operator's correction takes any MBID string, because an operator is trusted, audited, 2FA-gated
and IP-allowlisted, and is expected to have looked the artist up on setlist.fm. None of that is true
of an entitled user, so the input is not the same input. The pick's parameter is a *selection*, not a
value: the server validates the submitted MBID against the candidate list it stored on that band's
refresh record (AC-6.4) and rejects anything else. The user therefore cannot point a shared `Band` at
an artist setlist.fm's own search never proposed for that name — the set of reachable wrong answers
shrinks from "every MBID in existence" to "the handful of same-named bands", which is the difference
between vandalism and a mistake. It also means the pick makes no outbound call: the MBID and the
canonical name were already fetched (D-279). Cost accepted: the candidate list lives in Redis with the
refresh record, so a pick after that record expires is refused and the user re-triggers the refresh —
a cooldown-free path, because the refresh they are repeating is one whose result they never got to
use. That refusal is honest and self-explanatory (AC-6.6), and the alternative — a durable candidates
table — is D-264's rejected shape all over again.

**D-272 — Any returned candidate is selectable, not only exact-normalized-name matches.**
The tempting safeguard is to let a user pick only among candidates whose name normalizes exactly to
the band's, on the grounds that those are the "obviously right" ones. It is the wrong safeguard, and
it would gut the feature. `BandIdentityResolver` marks a band ambiguous in **two** shapes: more than
one exact normalized match, *or* candidates exist and **none** match exactly. The second is the
common shape — diacritics, punctuation, "The", a suffix in the setlist.fm name — and it is the shape
the investigation suspects for Boikot. Restricting picks to exact matches would leave that shape with
zero selectable candidates, i.e. exactly today's dead end, shipped with a picker in front of it.
Worse, it would concede the argument: if only auto-obvious cases were pickable, the auto-resolver
should have picked them. The whole value of a human here is judging the cases the normalizer cannot,
and `disambiguation` text ("Spanish ska-punk band") is information the matching rule does not have
and a user does. So the conservative rule stays where it belongs — on the **machine's** unaided
decision (spec 09 AC-2.3, unchanged) — and the human is allowed to be less conservative than the
machine, which is the only reason to ask a human at all. The risk this admits is contained by D-270,
D-271 and D-276 rather than by narrowing the list.

**D-273 — A user's pick is fully reversible by the operator, and by nobody else.**
`BandCrudController::performCorrectMbid()` and `performClearCache()` are untouched and already do
this: any band, any state, free-text MBID, audited, cache cleared. A user-resolved band is an
ordinary resolved band to them. Deliberately **not** added: a "re-open this band to ambiguous"
action, and any user-facing undo. `Band::resetResolution()` exists but stays where it is (the nightly
`no_presence` recheck), because re-opening is strictly worse than correcting — it discards a known
identity for an unknown one and re-spends a search — and because D-255's rule holds: the backoffice
gains no write for which there is no demonstrated need. The reversal path is one that already exists,
is already audited and is already tested.

**D-274 — The pick is audited under its own action name, `choose_band_mbid`.**
Same `AuditLogger`, same `subjectType`/`subjectId`/`field` as the operator's correction, same
request-thread write for the same IP/User-Agent reason (D-265) — and a **different action string**.
Reusing `correct_band_mbid` would save a constant and destroy the only cheap answer to the question
this amendment makes worth asking: *which band identities were chosen by users, and which by
operators?* Inferring it from the actor's roles is wrong the moment an operator uses the app as an
ordinary user, which is the likeliest first case. Two names, one query each. The pick is also the
one place in this feature where the audit entry records an outcome rather than an intent, because
here the intent *is* the outcome: the write has already happened when the entry is made.

**D-275 — The pick passes the entitlement and ownership gates, and none of the three throttles.**
The throttles (D-259) exist to bound setlist.fm spending. A pick spends nothing: it is a validated
`UPDATE` over data already in Redis. Charging it a cooldown or a daily-cap slot would price a free
action and, worse, would sometimes refuse the *resolution* of an ambiguity the user just paid for —
the user spends a refresh, learns the band is ambiguous, and is then told to come back in an hour to
say which one it is. That is the feature refusing to finish what it started. What the pick does keep
are the two gates that are about *authorisation* rather than cost: `CAN_REFRESH_SETLIST_NOW`, and a
concert of the caller's featuring the band (D-266's `422`). The cost this leaves open — a user
POSTing many picks — is bounded to one successful write per band by D-276, and every failure is a
Redis read and a `422`.

**D-276 — A band is user-resolvable exactly once.**
The moment a pick lands, the band holds an MBID, and D-270 closes the door behind it — for that user
and for every other. There is no second pick, no change-my-mind, no race that ends in a flip-flop:
the loser of a concurrent pick gets `422 band_already_resolved` (AC-6.8, AC-6.14) and, from the
client's point of view, good news (AC-10.10). This is what makes D-275's absence of throttling safe,
and it is why no "band identity churn" rate limit is needed: the state machine only has one edge out
of ambiguity.

**D-277 — The pick completes as a one-request refresh, exempt from the per-band cooldown.**
A resolved identity with no setlists is not a finished job — the user asked for setlists, not for an
MBID. So the pick dispatches the same `RefreshBandSetlistsMessage` with identity already settled: no
search, just the index page, **≤ 1** outbound request. It counts against the actor's daily cap and it
passes `SetlistFmBudget` like everything else (D-254 holds: no priority lane). It is exempt from the
**cooldown** specifically, and the exemption is derived from what the cooldown is for rather than
carved out for convenience: D-259 defines it as the throttle that refuses a *deterministically
identical* repeat of a question. The band's identity has just changed, so this is provably a
different question with a different answer — the one case the cooldown's own rationale does not
cover. Rejected alternative: make the user trigger a second refresh after picking, which would refuse
them for an hour on the cooldown from the refresh that produced the ambiguity, i.e. ship the fix and
then hide it behind the throttle.

**D-278 — Picking is its own operation, not a body variant of the trigger.**
`POST …/bands/{id}/setlist-refresh` keeps its empty body and its one meaning (D-262: a repeat returns
the in-flight refresh, never a `409`). Picking is `POST …/bands/{id}/setlist-refresh/resolution` with
`{selectedMbid}`. Overloading the trigger — "a body means pick, no body means refresh" — would make
its idempotent-repeat contract depend on payload presence, give one operation two failure vocabularies
(`429` throttles and `422` state errors), and make the OpenAPI document describe one operation that
does two things. Two URIs, two request shapes, two sets of status codes, one shared `GET` for state.
The `GET` is unchanged and unduplicated: the pick reuses the refresh's state machine and its
`Retry-After` polling (AC-6.13), so the client learns one contract.

**D-279 — `resolveAmbiguousChoice()` is a third entry point on `BandIdentityResolver`, writing through
`Band::resolveTo()`.**
Signature: `resolveAmbiguousChoice(Band $band, ArtistSearchCandidate $chosen, \DateTimeImmutable $now):
BandResolutionOutcome`. It takes the **candidate**, not a bare MBID string, so the canonical
setlist.fm name travels with it and `resolveTo($mbid, $setlistfmName, $now)` is called exactly as the
auto-resolver calls it — one identity write path, now with three callers (auto, operator, user) and
no fourth way to set an MBID. Validation of "is this candidate in the set we showed?" belongs to the
processor/coordinator, not here: this method's job is the state precondition (D-270) and the write.
It performs **no** outbound call (AC-2.9) and takes no budget, which is what makes the pick
synchronous while the refresh is asynchronous — there is nothing to wait for. Rejected alternative: a
free function or a processor-inline `resolveTo()` call. Both would put a fourth writer on the identity
column and defeat AC-2.8's static caller test, which is the mechanism keeping identity writes
countable.

**D-280 — The blast radius is unchanged in kind and wider in actor; that trade is stated, not hidden.**
Spec 09's R-4 accepted that a wrong shared disambiguation reaches every user, on the basis that the
chooser is one audited operator. This amendment keeps the consequence and changes the chooser, so the
honest accounting is: the *severity* is identical (wrong setlists for one band, for everyone, until
corrected), the *fix* is identical and still one audited action (D-273), the *detectability* is
better than before (a `choose_band_mbid` entry per pick, plus a dashboard figure — AC-9.5 — where an
operator's correction previously produced one entry nobody counted), and the *likelihood* is the
thing that genuinely rises. Against that: the chooser is not anonymous but a deliberately entitled
account (D-257), picking from a bounded server-produced set (D-271), into an empty field (D-270),
once (D-276), on a band they told us they are seeing live (D-266's ownership gate) — which is a
better-informed chooser than an operator who has never heard of the band, guessing from a support
ticket. The residual risk is real and is R-11, not a footnote.

### Suggested implementation order

1. `User.instantRefreshGrantedAt` + migration + `InstantRefreshVoter` + its static single-reader test.
2. `UserCrudController` grant/revoke, audited — so the flag can be exercised end to end before
   anything spends budget.
3. `SetlistRefreshCoordinator`: the three throttles, the lock, the Redis state record, fail-closed,
   with the concurrency test (AC-5.6). The gate exists before anything can call through it.
4. `BandIdentityResolver::forceResolve()` and the gateway/cache force-live path, with AC-2.6 and
   AC-2.8's structural tests. `SetlistGatewayIsOnlyDoorTest` must still pass untouched.
5. `RefreshBandSetlistsMessage` + handler, including the identity-already-settled flag (AC-6.12).
6. The API resource, processor, provider and output, including the zero-outbound-calls request test
   (AC-3.1) and the six-reason refusal test (AC-4.5).
7. **The pick**: candidate list on the refresh record (AC-6.4), `resolveAmbiguousChoice()` with its
   state precondition and no-outbound-call test (AC-2.9), the `…/resolution` operation and
   `ResolveBandIdentityProcessor`, the `choose_band_mbid` audit entry, and the concurrency test
   (AC-6.14). Built after the refresh path because it consumes that path's stored candidates and
   completes through its message.
8. `Me.canRefreshSetlistNow`, dashboard figures including the user-resolution count (AC-9.5).
9. Regenerate client types; the result-screen action, its polling, its copy, and the candidate list
   with its confirmation step (AC-10.6, AC-10.8).
10. Documentation and `/doc-check` before the PR.

---

## Out of Scope

| Not in this feature | Why / where it goes |
|---|---|
| **A user entering a free-text MBID** | D-271. Users select from a server-produced candidate set; typing an MBID stays the operator's audited action |
| **A user correcting or re-picking an already-resolved band** — their own pick included | D-270, D-273, D-276. One user-resolvable transition per band; `correctMbid` + `clearSetlistCache` is the reversal |
| **A backoffice "re-open this band to ambiguous" action** | D-273. Correcting is strictly better than re-opening, and D-255's no-new-admin-writes-without-need rule applies |
| **A provenance column on `Band`** (`resolvedBy`, `resolvedByUser`, …) | D-274, AC-8.9. The audit log answers who chose; the band row records only the identity |
| **A per-user override of a shared band identity** | D-57, unchanged. Still an additive change to a table that does not exist |
| **A backoffice "refresh this band now" button** | D-255. D-67's rejection stands for `/admin`; operators have MBID correction, cache clear and `app:setlist:refresh` |
| **A separate budget or quota for entitled users** | D-254. Same pool, same gate, same refusals. A second counter is a first counter someone forgets |
| **`EntitlementPlan`, `UserEntitlement`, `QuotaService`, tiers, pricing** | Prompt 22 and prompt 23. This ships one nullable timestamp (D-267) |
| **Per-user quotas on ordinary setlist reads** | Prompt 22. This feature throttles one action, not the read path |
| **Forcing a re-fetch of an already-resolved band's identity** | D-263 / D-56. Correcting a wrong MBID is the operator's audited action |
| **Forcing a re-fetch of immutable data** (setlist detail, index page ≥ 2) | D-263 / D-59. Budget spent on a guaranteed identical answer |
| **Refreshing a whole concert's lineup in one call** | A festival lineup is 12 bands and 24 requests behind one button — precisely D-67's original objection, and it is right about this shape. Band-scoped only |
| **Automatically re-running playlist generation after a successful refresh** | The user is on the result screen and can retry; chaining a generation onto a refresh makes one button spend two budgets |
| **Push/email notification when a refresh finishes** | No notification infrastructure exists. Polling is sufficient for a 1–3 second operation |
| **Cancelling an in-flight refresh** | AC-7.6. It finishes in seconds; a cancellation path costs more than it saves |
| **Changing the nightly job** | `app:setlist:refresh` is untouched, including its 25 % share and its 30-day `no_presence` interval |
| **Making `User.roles` mutable** | D-257 |

---

## Dependencies

**Must be true before implementation begins**

| Dependency | Provides | Status |
|---|---|---|
| **Spec 09 merged — setlist.fm integration** | `SetlistGateway`, `SetlistCache`, `SetlistFmBudget`, `BandIdentityResolver`, `Band::resetResolution()`, `FreshnessEnvelope`, `BandSearchCandidateOutput`, the dashboard panel to extend | **Met** |
| **Spec 08 merged — backoffice foundation** | `AuditLogger` (accepting any `User`, no signature change needed), `AbstractAdminCrudController`, `UserCrudController`, the 2FA-gated firewall | **Met** |
| **Specs 14/16 merged — playlist fast mode + UI** | `noSetlistCause` per band, the `Retry-After` polling contract, the result screen the action attaches to | **Met** |
| **`2026-08-27-admin-set-email-verified` merged** | The exact confirm → CSRF POST → audit → redirect shape for the grant action | **To confirm** — the pattern can be copied from `performToggleActive()` regardless; only the copy-paste source changes |
| Redis reachable and shared across web and workers | Cooldown, per-user cap, refresh state, telemetry, and the pre-existing budget gate | **Met** |
| A running Messenger worker in every environment that serves this endpoint | AC-3.2's async execution. A deployment with no worker accepts triggers that never run | **To confirm** — the playlist pipeline already requires this, so it is a documentation check, not new infrastructure |
| `symfony/lock` | AC-5.6's per-band single-flight | **Met** |

**Depended on by**

- **Prompt 22 (entitlement and quota seam)** — inherits three obligations from D-267.
- **Any future band-identity work** — inherits `Band::resolveTo()` as the single identity write with
  exactly three callers (auto, operator, user) and `choose_band_mbid` / `correct_band_mbid` as the
  queryable record of which kind of actor chose (D-274, D-279).

**Assumptions** *(labelled as assumptions, not verified facts)*

- A one-hour per-band cooldown is long enough to stop repeat spending and short enough not to feel
  punitive. Unmeasured; env-configurable; expected to be tuned from US-9's dashboard.
- Five triggers per user per day is generous for the real use case (a user has a handful of upcoming
  concerts, not fifty).
- The population of entitled users stays small at MVP scale, so the reserve rarely fires and the cap
  is the binding constraint.
- A refresh completes within a few seconds in the normal case, making polling adequate and
  notifications unnecessary.
- Most stuck bands in production are `unresolved` or `no_presence` rather than `ambiguous`. This is a
  guess, and it now matters less than it did: with D-270 the feature covers all four states, so the
  distribution changes only *which half* of the feature does the work. **US-9's counters (AC-9.5)
  should be read after a month** to learn the real split.
- A user shown two same-named bands with setlist.fm's `disambiguation` text can tell which one they
  saw live. Unverified, and the thing the whole pick rests on. It is a better-founded assumption than
  the operator equivalent (an operator has neither the ticket nor the context), but it is still an
  assumption; AC-10.8's confirmation step and AC-9.5's counter are how a wrong one becomes visible.
- Concurrent picks on the same band are rare enough that a lost race needing a `422` is an acceptable
  outcome rather than a queueing problem (AC-6.14).

---

## Risks and Open Questions

| # | Risk | Impact | Mitigation / decision |
|---|---|---|---|
| R-1 | **The exception erodes.** "Entitled users only" becomes "all users" one PR at a time, and D-65's argument is lost | High — it is the failure mode D-65 was written to prevent | The gate is one voter with one reader (D-258, AC-7.3), so widening it is a visible, reviewable, single-line change rather than a drift. The three throttles apply to *everyone*, including a hypothetically widened population, and the reserve (D-259) is specifically the throttle that still works if entitlement is granted broadly |
| R-2 | **An entitled user's refresh exhausts the budget for unentitled users** | Medium — the accepted cost of D-254 | Bounded by arithmetic (US-5): one user ≤ 0.7 % of the day; the population can never touch the last 10 %. Not eliminated, and the spec says so rather than claiming otherwise |
| R-3 | ~~**The feature does not fix the case that motivated it**~~ — **closed by the 2026-08-27 amendment.** D-270 – D-280 give the `ambiguous` state a user-facing exit, so all four stuck states are fixed rather than three fixed and one explained | — | Closed. The risk it becomes is R-11: the fix is a shared write |
| R-4 | **Users retry-hammer the action** and generate refusal load | Low | Refusals are cheap Redis reads that never reach setlist.fm, never queue a message, and never write a row (AC-8.4). The client disables the control with a countdown (AC-10.3) |
| R-5 | **A Redis outage disables the feature entirely** | Low | By design: fail-closed (AC-5.3). The rest of the product's setlist paths already degrade the same way (R-7 of spec 09). Refusing an on-demand spend when the throttles are unreadable is the only safe direction |
| R-6 | **`forceResolve()` gets called from a read path** by a future feature, resurrecting the problem D-65 forbade | High — silently reintroduces on-demand checks on every read | Structural, not procedural: AC-2.8's static test restricts the callers, mirroring D-58's enforcement of the gateway seam |
| R-7 | **The audit table gains a new class of actor** and backoffice screens or reports assume actors are admins | Low | AC-8.6 asserts the audit list renders an ordinary-user actor correctly. `AuditLogger`'s signature and digest behaviour are unchanged (D-265) |
| R-8 | **A deployment without a Messenger worker** accepts triggers that never run | Medium and quiet | Dependencies table flags it; the refresh state stays `queued` and the client's polling surfaces a stall rather than reporting success. The playlist pipeline has the same requirement today |
| R-9 | **Prompt 22 has to unpick this** when it builds the real entitlement system | Low | D-267 writes down the three obligations explicitly, and AC-7.3's single-reader rule is what keeps the migration to one class |
| R-10 | **The four new env vars are guesses** | Low | All four are configuration, all four are on the dashboard (US-9), and the assumptions list says plainly that they are unmeasured |
| R-11 | **A user picks the wrong band, and every user of that band gets the wrong setlists** — the cost of D-270 on top of D-57 | Medium, and the amendment's headline risk | Not eliminated; bounded and made visible. Bounded by the pick being candidate-set-only (D-271), vacancy-only (D-270) and once-only (D-276), by an ownership gate meaning the chooser has a ticket to that show (D-266), and by entitlement being a deliberate grant. Visible through a `choose_band_mbid` audit entry per pick and a dashboard count (AC-8.7, AC-9.5) — strictly better detection than the operator path had. Fixed by the same single audited action as before (D-273). The severity is identical to spec 09's accepted R-4; the likelihood is what rises, and D-280 says so |
| R-12 | **The candidate list expires from Redis before the user picks**, and a legitimate pick is refused | Low | `mbid_not_a_candidate` with copy that offers to look again (AC-6.6, AC-10.10), and re-triggering costs one refresh. The alternative — a durable candidates table — is the shape D-264 rejected on stronger grounds |
| R-13 | **Users treat the picker as a guess** and pick the first row to make the error go away | Medium | AC-10.8 makes it a confirmed choice naming the specific band and saying the choice is shared and support-only to change. AC-9.5 makes a spike in picks visible. If the count is high and support corrections follow it, the safeguard to add is restricting picks to bands whose concert has already happened — a user who has *been* to the show knows; a user who has only bought a ticket may not. Deliberately not added now: it is unmeasured, and it would block the upcoming-concert case that motivates most generation |
| R-14 | **`Band::resolveTo()` grows a fourth caller** and identity writes stop being countable | Medium | AC-2.8's static test lists the permitted callers, the same structural enforcement as D-58's single door. A fourth writer fails CI rather than review |

**Open questions — resolved 2026-08-27**

All three are decided. Nothing in this spec is deliberately left open.

1. **Should an entitled user be allowed to pick the MBID when the refresh comes back `ambiguous`?**
   **Decided: yes — in this spec.** The recommendation had been to defer it (D-268); the decision went
   the other way, on the grounds that deferring ships a feature that explains the reported bug instead
   of fixing it. The blast-radius questions the recommendation raised were not waved through: *does a
   user-made choice differ from an operator-made one* is answered by D-270, D-271 and D-276 (yes —
   vacancy-only, candidate-set-only, once-only, three ways narrower than the operator's write); *can an
   operator override it* by D-273 (yes, through the unchanged existing action); *is it audited
   distinguishably* by D-274 (yes, `choose_band_mbid`). The residual risk is R-11, accepted with its
   mitigations written down. **D-268 is superseded, not deleted.**
2. **Are the four defaults right — 1 hour, 5/day, 10 % reserve, 3s token wait?** **Decided: accepted
   as starting points**, exactly as spec 09's 7-day window and 25 % share were, to be tuned from the
   dashboard once there is real usage. The cap is the one most likely to be wrong; 5 stays
   deliberately low because raising a limit is easier than lowering one. The pick added by D-270 does
   not change these numbers: it spends nothing itself (D-275) and completes in ≤ 1 request (D-277).
3. **Should the entitlement be granted to all users in a non-production environment?** **Decided: no**
   — admin-only grant, in every environment including development. An env flag that grants an
   entitlement is exactly the shape that leaks into production config. Granting it in `/admin` takes
   one click and exercises the real path, which is now the path that can write a shared band identity —
   a stronger reason for the answer than when the question was asked.

---

## Documentation to update in this branch

Per `CLAUDE.md`'s mandatory documentation check (run `/doc-check` before committing):

- **`docs/specs/2026-08-22-setlistfm-integration.md`** — add **D-254** and **D-255**, add a pointer
  line to **D-65** and **D-67**, and update the *Out of Scope* row for *"A user-facing 'refresh now'
  control"*. **Nothing is deleted.** *(Done in this branch as part of writing this spec.)*
  **Still to do in this branch:** add **D-270 – D-272** to the same amendment section, a scope note on
  **D-57** (the chooser set widens; what is stored does not change) and a widened mitigation note on
  **R-4** pointing at R-11 here. Spec 09's AC-2.4 already says "the user's chosen MBID" and needs no
  edit — this is the half of its own US-2 that was never built.
- **`docs/env-vars.md`** *and* **`backend/.env.example`** — the four new variables. Both or neither.
- **`docs/architecture.md`** — record **D-254**–**D-280**; update §5 with the force-live path, the
  three throttles and the user-side identity resolution, §9 with the new dashboard figures, and §11's
  identity-write account with `Band::resolveTo()`'s third caller.
- **`docs/external-apis.md`** — no terms change; note that on-demand refresh spends from the same
  1,440/day pool and is bounded by the reserve, so the section's budget arithmetic stays accurate.
- **API Platform resources** — the new operations and `Me.canRefreshSetlistNow` regenerate the OpenAPI
  document, the only place endpoints are described. **No endpoint list in this spec or any README.**
- **`docs/investigations/2026-08-27-boikot-setlist-not-found.md`** — the *Follow-up* section is
  updated to record that the amended spec covers the `ambiguous` case the investigation named as the
  most likely cause (D-270 – D-280), and that the operator path remains the correction path.
  *(Done alongside this amendment.)*
- **Root `README.md`** — note that the Messenger worker is required for on-demand refresh, alongside
  the existing playlist-pipeline note.
- **`frontend/README.md`** — no change; no stack or structure change.
- **`CLAUDE.md`** — recommended addendum to the *"setlist.fm responses are always cached"* rule: a
  sentence recording that on-demand refresh is the single entitled exception to the nightly-only
  freshness policy, and that it spends from the same budget. One sentence, so a future reader of the
  rule does not have to find this spec to learn the exception exists.

---

**Review requested.** This spec proposes decisions **D-254**–**D-280**, amends **D-65**/**D-67** and
widens **D-57** of `2026-08-22-setlistfm-integration.md`, and supersedes its own **D-268**. It is not
implementable until approved.

The three most consequential — and the three most worth disagreeing with — are **D-254** (an
on-demand budget spend exists at all, which spec 09 rejected by name, and which means an entitled
user's refresh can be the request that exhausts the day for someone else), **D-257** (entitlement as a
new column rather than a role, trading one migration and one `Me` field for keeping `User.roles`
literally immutable) and **D-270** (an entitled user can write a band's identity — shared by every
user of that band — where previously only a 2FA-gated operator could, bounded by D-271's candidate-set
input, D-276's one-shot rule and D-273's unchanged operator reversal, and accepted as R-11).

**Amendment history of this document**

| Date | Change |
|---|---|
| 2026-08-27 | Written. D-254 – D-269; Open Questions 1–3 left for the user |
| 2026-08-27 | Amended on the user's decision on Open Question 1: user-side disambiguation brought **in** scope. Adds **D-270 – D-280**, supersedes **D-268**, rewrites US-6, extends US-2/US-4/US-8/US-9/US-10/US-11, closes R-3 and adds R-11 – R-14. Open Questions 2 and 3 accepted as recommended. Nothing removed |
