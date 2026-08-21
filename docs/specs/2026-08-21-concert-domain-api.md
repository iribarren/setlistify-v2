# Concert Domain and API

| | |
|---|---|
| **Spec ID** | `2026-08-21-concert-domain-api` |
| **Backlog prompt** | `docs/prompts/05-concert-domain-api.md` |
| **Command** | `/feature concert-domain-api` |
| **Primary agent** | `backend-engineer` (backend only; the client types are regenerated, not hand-written) |
| **Branch** | `feature/concert-domain-api` |
| **Depends on** | `01` — backend skeleton (merged) · `04` — auth and accounts (merged, PR #4) |
| **Status** | **Draft — awaiting approval** |

---

## Overview

The application knows who its users are (prompt 04) and nothing else. `backend/src/Entity/` holds
`User` and three token entities; there is no product data at all. This feature creates the product.

A **Concert** is the thing a Setlistify user actually owns: *I went to these bands, on this date, at
this place, and it cost me this much.* Everything downstream hangs off it — `docs/architecture.md`
§10 sketches `User ─< Concert ─< ConcertBand >─ Band` with `Playlist` hanging off `Concert`, and
prompts 07 (UI), 09 (setlist.fm), 14–17 (playlists), 19 (player), 20 (notes) and 21 (sharing) all
start from a concert that exists. Nothing after this prompt can be built without it.

Four modelling commitments define the result:

1. **A concert has many bands, and a band is shared.** Festivals and support acts are the normal
   case, not the exception, and "Radiohead" typed by two different users must be *one* row — because
   prompt 09 attaches a setlist.fm identifier to a band, and that identifier must be resolved once
   for everyone, not once per user. `Band` is therefore its own entity with a normalized name and a
   dedup rule, and `ConcertBand` carries the billing order so a lineup keeps its shape.
2. **`upcoming` / `past` is derived, never stored as a flag.** A boolean column would be correct for
   exactly one day and wrong forever after. Status is a comparison against the current instant, and
   this spec makes the timezone that comparison uses an explicit decision (D-24) rather than an
   accident of the server's `date_default_timezone`.
3. **Ownership is structural.** A user must not be able to read, update or delete another user's
   concert — and must not be able to *learn that it exists*. That means **404, not 403**, on every
   operation, which in turn means the ownership filter belongs in the query, not only in a voter
   (D-27).
4. **The API contract is generated, not written.** Every operation is an API Platform resource so
   the OpenAPI document stays the single source of truth (`CLAUDE.md`), and the Expo client's types
   are regenerated from it.

This feature ships **no UI** (prompt 07) and **no setlist lookup** (prompt 09). It ships the domain
those features consume, and the authorization shape every later resource will copy.

## Goals

| Goal | Success looks like |
|---|---|
| The core entity exists | `Concert`, `Band`, `ConcertBand` mapped, migrated, and matching `docs/architecture.md` §10 |
| A lineup keeps its shape | A concert created with `[headliner, support, opener]` reads back in that exact order, always |
| Bands are shared, not duplicated | Two users typing the same band name produce one `Band` row; deleting either concert leaves it intact |
| Upcoming/past is trustworthy | A concert dated today is classified the same way at 00:01 and 23:59, from any client timezone, with a rule written down and tested |
| One user's data is invisible to another | Every cross-user request returns **404**; proven by tests on read, update *and* delete |
| The list endpoint is usable at scale | Pagination, upcoming/past filter, date sort and band-name search, all index-backed |
| Bad input is explained, not just rejected | RFC 7807 with per-field violations on every validation failure |
| The contract holds at build time | The regenerated `frontend/api/` types compile, and no endpoint is documented in a README |

## User Stories

### US-1 — Record a concert with its lineup

> As a **logged-in user**, I want to record a concert with one or more bands in billing order, so
> that my lineup is preserved exactly as the show was billed.

**Acceptance criteria**

- **AC-1.1** `POST /api/concerts` accepts a body containing `date`, `timezone`, an ordered `lineup`
  array, and the optional fields of US-2, and returns **201** with the created concert.
- **AC-1.2** The request is bound to a `ConcertInput` DTO (D-29). There is no `owner`, `id`,
  `createdAt` or `status` field to send; the owner is taken from the authenticated token, never from
  the payload.
- **AC-1.3** `lineup` is an ordered array of `{ name }` (or `{ bandId }`) entries. Array index is
  the billing order: index 0 is the headliner. The order submitted is the order persisted in
  `ConcertBand.billingOrder` (0-based, contiguous, no gaps).
- **AC-1.4** Reading the concert back — immediately or in a later request, via item or collection —
  returns the lineup in `billingOrder` ascending. The ordering is enforced by the mapping
  (`#[ORM\OrderBy]`), not by chance of insertion order.
- **AC-1.5** At least **1** and at most **30** bands (D-31 bounds the lineup; a 30-band festival day
  is generous and the bound exists to stop an unbounded write). Zero bands is a 422.
- **AC-1.6** The same band may not appear twice in one lineup — 422 with the offending index.
- **AC-1.7** The response body exposes each lineup entry as `{ band: { id, name }, billingOrder }`,
  never a bare string, so prompt 09 can add `setlistfmMbid` to the band object without a breaking
  change.
- **AC-1.8** Creating a concert is a single transaction: if band resolution fails, no partial
  concert is persisted.

### US-2 — Record the practical details of the show

> As a **logged-in user**, I want to note where the concert is, what the ticket cost and when doors
> open, so that my concert list is also my record of the evening.

**Acceptance criteria**

- **AC-2.1** All of the following are **optional**: `venue.name`, `venue.city`,
  `venue.countryCode`, `ticketPrice`, `doorsTime`, `startTime`, `note`. A concert with only a date
  and one band is valid and is the minimum viable record.
- **AC-2.2** `venue` is a nested object in the API (`{ name, city, countryCode }`), backed by a
  Doctrine embeddable (D-26). `countryCode` is validated as ISO 3166-1 alpha-2, uppercased on write.
- **AC-2.3** `ticketPrice` is `{ amount, currency }` where `amount` is an **integer in the
  currency's minor units** and `currency` is ISO 4217 alpha-3, uppercased (D-28). Both fields are
  required together — an amount without a currency, or a currency without an amount, is a 422.
- **AC-2.4** `amount` must be `>= 0`. Zero is valid and meaningful (guest list, free show).
- **AC-2.5** `doorsTime` and `startTime` are local wall-clock times (`HH:MM`) in the concert's
  timezone (D-24), stored as `TIME` — never as an instant of their own. If both are present,
  `doorsTime <= startTime`, otherwise 422.
- **AC-2.6** `note` is plain text, max **2000** characters, stored and returned verbatim. It is
  never interpreted as HTML or Markdown by the API (D-30).
- **AC-2.7** Omitted optional fields are returned as `null`, not absent, so the generated client
  types are stable.

### US-3 — See my concerts, split into upcoming and past

> As a **logged-in user**, I want my concerts listed and separable into what is coming up and what
> already happened, so that the app can show me a plan and a history.

**Acceptance criteria**

- **AC-3.1** `GET /api/concerts` returns **only** the authenticated user's concerts. Never anyone
  else's, under any filter, sort or page.
- **AC-3.2** Each concert carries a derived, read-only `status` of `upcoming` or `past`. It is
  computed, not stored as a flag (D-24), and cannot be written.
- **AC-3.3** `?status=upcoming` and `?status=past` filter the collection; omitting the parameter
  returns both. An unrecognised value is a 422, not a silently empty list.
- **AC-3.4** Sorting by date is supported in both directions (`?order[date]=asc|desc`). Default
  ordering: **`date` ascending for `upcoming`** (soonest first) and **`date` descending otherwise**
  (most recent first), with `id` as a stable tiebreaker so pagination never repeats or skips a row.
- **AC-3.5** Pagination is API Platform's default Hydra collection with a page size of **20** and a
  client-adjustable size capped at **100**.
- **AC-3.6** **Boundary behaviour.** A concert dated *today* is `upcoming` until the end of its own
  local calendar day in its own timezone, and `past` from that moment on (D-24). Verified by tests
  that pin the clock at: the instant before local midnight (expect `upcoming`), the instant after
  (expect `past`), and the same concert evaluated from a client in a far-offset timezone (expect the
  *same* answer both times).
- **AC-3.7** The filter is index-backed: filtering by status and sorting by date does not require a
  per-row timezone computation at query time.
- **AC-3.8** An authenticated user with no concerts gets **200** with an empty collection, not 404.

### US-4 — Find a concert by band name

> As a **logged-in user**, I want to search my concerts by band, so that I can find the night I saw
> a particular band without scrolling.

**Acceptance criteria**

- **AC-4.1** `?band=<query>` filters the user's concerts to those whose lineup contains a band
  matching the query.
- **AC-4.2** Matching uses the **same normalization** as dedup (D-25): case-, diacritic- and
  article-insensitive substring match. Searching `sigur ros` finds *Sigur Rós*; searching `beatles`
  finds *The Beatles*.
- **AC-4.3** The search combines with `status`, sorting and pagination; results are not duplicated
  when several bands in one lineup match.
- **AC-4.4** An empty or whitespace-only `band` parameter is treated as absent, not as a match-all
  or a 500.

### US-5 — Correct a concert I recorded

> As a **logged-in user**, I want to fix a concert's details — including its lineup — so that a
> typo or a late-announced support act does not force me to delete and re-create it.

**Acceptance criteria**

- **AC-5.1** `PATCH /api/concerts/{id}` with `application/merge-patch+json` updates the supplied
  fields and leaves the rest untouched. Returns **200** with the updated concert.
- **AC-5.2** When `lineup` is present, it **replaces** the whole lineup and re-derives
  `billingOrder` from array position. Partial lineup edits are not supported — the array is the
  lineup.
- **AC-5.3** Replacing a lineup removes only the `ConcertBand` rows; the `Band` rows survive
  (US-8).
- **AC-5.4** Changing `date` or `timezone` re-derives the stored boundary instant (D-24) in the same
  transaction, so `status` is immediately consistent with the new date.
- **AC-5.5** `owner`, `id`, `createdAt` and `status` are not writable; sending them is ignored by
  the DTO binding rather than partially applied.
- **AC-5.6** `updatedAt` changes; `createdAt` does not.
- **AC-5.7** All of US-9's validation applies identically to updates.

### US-6 — Delete a concert

> As a **logged-in user**, I want to delete a concert I recorded by mistake, so that my history is
> accurate.

**Acceptance criteria**

- **AC-6.1** `DELETE /api/concerts/{id}` returns **204** and the concert is gone from subsequent
  reads and listings.
- **AC-6.2** The concert's `ConcertBand` rows are removed with it (orphan removal / cascade at the
  join only).
- **AC-6.3** **`Band` rows are never deleted**, even if the deleted concert was the last one
  referencing the band — bands are shared, and prompt 09 will have invested identity resolution in
  them. Covered by test.
- **AC-6.4** Deleting a concert that does not exist, or belongs to someone else, returns **404**
  (US-7). The two cases are indistinguishable.
- **AC-6.5** Deletion is hard, not soft. There is no undo, and no `deletedAt` column — nothing in
  this scope needs one, and a soft-delete column that no filter respects is a leak waiting to
  happen.

### US-7 — My concerts are mine alone

> As a **logged-in user**, I want other users to be unable to see, change or even detect my
> concerts, so that my history is private by construction.

**Acceptance criteria**

- **AC-7.1** Every collection query is filtered to `owner = current user` by a Doctrine ORM query
  extension (D-27), before any other filter runs.
- **AC-7.2** Item `GET`, `PATCH` and `DELETE` on another user's concert return **404** with an RFC
  7807 body identical to that of a genuinely non-existent id. Not 403 — existence must not leak.
  **Covered by an explicit test per verb.**
- **AC-7.3** An anonymous request to any concert endpoint returns **401**.
- **AC-7.4** `owner` cannot be set or changed through the API at all: it is absent from the input
  DTO and assigned server-side from the security token (D-29).
- **AC-7.5** A voter (`ConcertVoter`) additionally guards item operations, so that any future code
  path reaching a concert outside the filtered query is still denied. Belt and braces: the extension
  produces the 404, the voter prevents the accident.
- **AC-7.6** A test asserts that the ownership extension applies to the **collection count** too — a
  total of 7 in a Hydra response must not include other users' rows.

### US-8 — Bands are shared, not duplicated

> As the **system**, I want one row per real band across all users, so that identity work done once
> (prompt 09's setlist.fm ids) benefits every user.

**Acceptance criteria**

- **AC-8.1** `Band` has `name` (as first typed), `normalizedName`, nullable external identifier
  columns (`setlistfmMbid`, unused here), and timestamps.
- **AC-8.2** `normalizedName` carries a **unique index**. Two concerts created with band names that
  normalize identically share one `Band` row — proven by test across two different users.
- **AC-8.3** Normalization (D-25): trim, collapse internal whitespace, Unicode NFKD + diacritic
  strip, lowercase, strip a leading definite article, remove punctuation. `The Beatles` → `beatles`,
  `Sigur Rós` → `sigur ros`, `AC/DC` → `acdc`.
- **AC-8.4** The displayed `name` is whatever the **first** creator typed; a later creator whose
  spelling normalizes to the same value does not overwrite it. Their input is not lost silently —
  the response returns the canonical `name`, so the client can show what was actually stored.
- **AC-8.5** Band resolution is **race-safe**: two simultaneous creates of the same new band produce
  one row, not a 500. The unique constraint is the arbiter; the resolver catches the violation and
  re-reads.
- **AC-8.6** Band creation happens only as a side effect of concert creation/update in this feature.
  There is no public `POST /api/bands`.
- **AC-8.7** A read-only `GET /api/bands?q=` typeahead is **optional** in this scope; if shipped, it
  returns `{ id, name }` only, is rate-limited, and exposes nothing user-scoped (no counts, no "who
  saw them"). Otherwise deferred to prompt 07.

### US-9 — Invalid input is explained precisely

> As a **client developer**, I want validation failures to name the field that failed and why, so
> that the UI can put the error next to the input.

**Acceptance criteria**

- **AC-9.1** All validation failures return **422** with `application/problem+json` (RFC 7807) and a
  `violations` array of `{ propertyPath, message }` — API Platform's default shape, not a bespoke
  one.
- **AC-9.2** `date` is required, ISO-8601 calendar date, and must fall within a sane window:
  **not before 1900-01-01** and **not more than 5 years in the future**. Both bounds are 422s with
  distinct messages.
- **AC-9.3** `timezone` is required and must be a valid IANA identifier (`Europe/Madrid`), validated
  against PHP's timezone list. `UTC` is accepted; a fixed offset like `+02:00` is not (D-24).
- **AC-9.4** Band names are trimmed, 1–120 characters after trimming, and a name that normalizes to
  the empty string (e.g. `"---"`) is a 422 rather than a band with an empty `normalizedName`.
- **AC-9.5** `propertyPath` for lineup errors is indexed (`lineup[2].name`) so the client can target
  the right row.
- **AC-9.6** Malformed JSON returns **400**; a well-formed body with invalid values returns **422**.
  The distinction is not blurred.
- **AC-9.7** No validation message echoes anything that is not the user's own input.

### US-10 — The contract is generated and consumed, not written twice

> As a **client developer**, I want the concert endpoints to appear in the OpenAPI document and in
> the generated TypeScript types, so that a breaking API change is a compile error, not a runtime
> surprise.

**Acceptance criteria**

- **AC-10.1** Every operation is declared through API Platform resources; the OpenAPI document at
  `/api/docs` shows correct request/response schemas, status codes (200/201/204/400/401/404/422),
  the `status`/`band` filters and the pagination parameters.
- **AC-10.2** `frontend/api/` is regenerated (`npm run generate:api`) in this branch and committed;
  `npm run typecheck` passes on the frontend with the new types present.
- **AC-10.3** **No endpoint is listed in any README** (`CLAUDE.md`).
- **AC-10.4** No hand-written request or response type for concerts exists anywhere in `frontend/`.
- **AC-10.5** The backoffice is untouched — no concert admin CRUD in this branch (prompt 08).

### US-11 — The domain is tested and green in CI

> As a **maintainer**, I want the guarantees above proven by tests, so that a later prompt cannot
> quietly break ownership isolation or band sharing.

**Acceptance criteria**

- **AC-11.1** Functional API tests cover: create with multi-band lineup and read-back order (US-1),
  optional-field round-trip (US-2), list pagination/filter/sort (US-3), band search (US-4), patch
  including lineup replacement (US-5), delete (US-6).
- **AC-11.2** **Ownership isolation** has a dedicated test per verb (GET item, PATCH, DELETE) with
  two seeded users, asserting **404** and an identical body to the missing-id case.
- **AC-11.3** **Upcoming/past boundary** is tested with a frozen clock at three points (AC-3.6),
  including a concert in `Pacific/Auckland` evaluated from a `America/Los_Angeles` client.
- **AC-11.4** **Band dedup** is tested across two users and across differing spellings that
  normalize alike; a concurrency test (or a direct constraint-violation test) covers AC-8.5.
- **AC-11.5** **Band survival on delete** is tested (AC-6.3).
- **AC-11.6** Validation tests cover each 422 in US-9 and assert the RFC 7807 shape.
- **AC-11.7** PHPStan level 9 passes and the whole suite runs in CI with **no external network
  access** (D-2), against the real PostgreSQL service — not mocks.

## Technical Approach

### Backend (`backend/`)

| Area | Shape |
|---|---|
| Entities | `Concert` (owner FK, `date` DATE, `timezone` VARCHAR, `pastAfter` TIMESTAMPTZ derived, `venue` embeddable, `priceAmount` INT null, `priceCurrency` CHAR(3) null, `doorsTime`/`startTime` TIME null, `note` TEXT null, timestamps), `Band` (`name`, `normalizedName` unique, `setlistfmMbid` null, timestamps), `ConcertBand` (concert FK, band FK, `billingOrder` SMALLINT, unique on (concert, band) and (concert, billingOrder)) |
| Value objects | `Venue` embeddable (`name`, `city`, `countryCode`) — D-26; `Money` handling via two columns, D-28 |
| API surface | `GET`/`POST /api/concerts`, `GET`/`PATCH`/`DELETE /api/concerts/{id}`, optionally `GET /api/bands` (AC-8.7) — all API Platform, so OpenAPI regenerates itself |
| DTOs | `ConcertInput` (write) and `ConcertOutput` (read) with state processor/provider, continuing D-22's rule that no entity is directly writable |
| Authorization | `ConcertOwnerExtension` implementing `QueryCollectionExtensionInterface` + `QueryItemExtensionInterface`, plus `ConcertVoter` — D-27 |
| Services | `Service/Concert/BandResolver` (normalization + race-safe get-or-create), `Service/Concert/ConcertScheduler` (derives `pastAfter` from `date` + `timezone`) — business rules out of the HTTP layer per `docs/architecture.md` §3 |
| Filters | API Platform `OrderFilter` on `date`; custom `ConcertStatusFilter` (maps to a `pastAfter` comparison) and `BandNameFilter` (join + normalized `ILIKE`) so both appear in OpenAPI |
| Indexes | `(owner_id, past_after)`, `(owner_id, date)`, `band.normalized_name` unique, `concert_band(concert_id, billing_order)` unique |
| Migration | One Doctrine migration creating all three tables plus indexes |

### Decisions

Numbered from **D-24**; `D-1`–`D-3` are project-wide (`docs/architecture.md`), `D-4`–`D-9` belong to
the backend skeleton spec, `D-10`–`D-17` to the frontend skeleton spec and `D-18`–`D-23` to the auth
spec.

**D-24 — A concert is a local calendar date plus an IANA timezone, and stays `upcoming` until the
end of its own local day.** *This resolves the prompt's first open question, and it is the decision
most expensive to reverse.*

The trap is that a concert is not an instant — it is an *event in a place*. Three candidate models:

| Option | What breaks |
|---|---|
| `DATETIME` in UTC only | The venue's local time is unrecoverable. A 21:00 Madrid show stored as 19:00Z displays as 19:00 to a user whose device is on UTC. Every read needs a timezone the row does not carry |
| Naive local `DATETIME`, no timezone | Ordering across timezones is wrong, and "is it past?" has no defined answer. Postgres `TIMESTAMP WITHOUT TIME ZONE` is exactly this trap |
| **Local `DATE` + IANA `timezone` + a derived UTC boundary instant** | Two columns instead of one, and a derived column to keep in sync on write |

We take the third. `date` is the calendar date **as printed on the ticket**, in the venue's local
time; `timezone` is the IANA identifier of that venue (`Europe/Madrid`), defaulting to the client's
device timezone at creation when the user has not said otherwise. `doorsTime`/`startTime` are local
wall-clock times against that same timezone, which is why they are `TIME` and not instants
(AC-2.5) — a venue that moves the start time does not want a DST recalculation.

Fixed offsets (`+02:00`) are rejected (AC-9.3) because they are wrong twice a year: a show booked in
July for November would carry the summer offset.

**The status rule: a concert is `upcoming` until midnight at the end of its local date, and `past`
thereafter.** Not "start time + N hours", and explicitly *not* relative to the viewer's timezone.
The reasoning: the person who cares is the person attending, and for them the concert is "tonight"
all day, and "last night" once the day is over — regardless of whether they are reading the app from
an airport in another hemisphere. Making status depend on the *viewer* would mean the same concert
being upcoming and past in two tabs, and would make the list unsortable server-side.

Implementation: on every write, `ConcertScheduler` computes
`pastAfter = (date + 1 day) at 00:00 in timezone, converted to UTC` and stores it as `TIMESTAMPTZ`.
Status is then `pastAfter <= now() ? past : upcoming` — a single indexed comparison, no per-row
timezone math at query time (AC-3.7), and no stale flag, because `pastAfter` is a deterministic
function of `date` + `timezone` recomputed whenever either changes (AC-5.4). A test asserts
`pastAfter` is recomputed on update; a nightly consistency check is *not* needed because nothing
else can write it.

The API always returns `date`, `timezone` and the derived `status`, so a client that disagrees with
our rule has everything it needs to compute its own.

**D-25 — Band dedup uses a deliberately simple normalization, and accepts false merges over false
splits.** Normalization is: trim → collapse whitespace → Unicode NFKD → strip combining marks →
lowercase → strip a leading article (`the `, `los `, `las `, `el `, `la `) → remove non-alphanumeric
characters. `AC/DC` → `acdc`, `Sigur Rós` → `sigur ros`, `The Beatles` → `beatles`.

This is wrong in both directions and that is understood. It will **falsely merge** distinct bands
whose names collapse to the same string (`The Band` and `Band`), and it will **falsely split**
genuinely different spellings it cannot see through (`Motörhead` vs `Motorhead` normalizes alike —
good — but `The The` vs `The` does not). We prefer the false merge here: a merged band is visible
and fixable, whereas a split band silently duplicates the setlist.fm resolution work prompt 09 is
about to invest, and doubles every future band-level feature.

Crucially, **normalization is a service, not a database function** (`BandResolver::normalize`), and
`normalizedName` is a stored column. Prompt 09 can therefore replace the rule with setlist.fm-backed
identity and re-derive the column in a migration, without touching any query. The rule is *not*
inlined into the search filter — US-4 calls the same service (AC-4.2), so search and dedup can never
drift apart.

**D-26 — Venue is an embeddable value object, not three loose columns and not an entity.**
*This resolves the prompt's third open question.* `Venue { name, city, countryCode }` is a Doctrine
`#[Embeddable]` mapped inline onto `concert` (`venue_name`, `venue_city`, `venue_country_code`), and
serialized as a nested `venue` object in the API. Why this shape:

- The **API contract is already the entity shape** we would want if venue were promoted. When prompt
  24 makes `Venue` a real table, the JSON stays `{ "venue": { "name": …, "city": … } }` and gains an
  `id` — an additive change, not a breaking one, and the generated client barely notices.
- The migration path is a data migration (`INSERT INTO venue SELECT DISTINCT …`) plus a mapping
  change, with no controller, DTO or client edit.
- Loose columns would have got us the same database but a flat JSON shape (`venueName`,
  `venueCity`) that *would* break on promotion.

All three fields stay free text and fully optional now; `countryCode` is the one validated field
(ISO 3166-1 alpha-2) because it is the join key a future venue table would want.

**D-27 — Ownership is enforced by a Doctrine query extension first, a voter second.** A voter alone
returns **403**, which tells an attacker the id exists — exactly what AC-7.2 forbids. A query
extension alone protects the ORM path but not code that loads a concert some other way. So both:

- `ConcertOwnerExtension` adds `WHERE owner = :current_user` to collection **and item** queries. The
  item query then finds nothing, and API Platform's standard "not found" path produces a 404 that is
  byte-identical to a genuinely missing id — the correct behaviour comes from the framework's normal
  flow, not from a bespoke exception.
- `ConcertVoter` denies item operations on a non-owned concert, so any future non-ORM path fails
  closed rather than open.

This pair is the pattern every later user-scoped resource (playlists, notes) copies, so it is worth
paying for once.

**D-28 — Money is stored as integer minor units plus an ISO 4217 code, and exposed that way.**
Floats are not a candidate — `19.99` is not representable and ticket prices are summed in later
features. Between a decimal string and integer minor units, we take minor units: `{ "amount": 4500,
"currency": "EUR" }` is €45.00, and `{ "amount": 4500, "currency": "JPY" }` is ¥4500, because the
currency's exponent is what decides. It survives JSON round-trips exactly, needs no arbitrary
precision type, and `Intl.NumberFormat` on the client formats it correctly per currency and locale
with no server-side formatting at all. The cost is that the API is not human-readable at a glance;
the field is documented in the OpenAPI description to compensate.

**D-29 — `Concert` is never a writable entity resource; DTOs bound the payload.** Continuing D-22.
`ConcertInput` has no `owner` field to attack (AC-7.4), no `status` to desynchronize, and no `id`.
`ConcertOutput` shapes the response, which is what lets `status` and the ordered lineup be computed
values rather than mapped columns. The state processor is the single place the owner is stamped from
the security token.

**D-30 — `note` is a plain-text field with no rendering contract.** Prompt 20 owns notes and reviews
as a feature (formatting, per-band notes, ratings, visibility). Here it is one nullable `TEXT`
column, length-bounded, stored and returned verbatim, never parsed. The API does not sanitize it —
sanitizing would corrupt legitimate text and would imply a rendering guarantee we are not making;
escaping belongs to whoever renders it, and React Native does so safely by default. Prompt 20 can
migrate the column into a richer model without an API break because nothing depends on its contents.

**D-31 — Bounds are set now, not "later when we see abuse".** Lineup 1–30 bands, band name 1–120
chars, note 2000 chars, page size max 100, date within [1900, now+5y]. Every one of these is a write
amplification or storage vector left open otherwise, and retrofitting a bound onto existing data is
strictly harder than starting with one. They are validation constants in one class, easy to raise.

### Suggested implementation order

1. `Band` + `BandResolver` + normalization, with the dedup and race tests (AC-8.2, AC-8.5) first —
   they are the ones most likely to reveal a wrong assumption.
2. `Concert` + `Venue` embeddable + `ConcertBand` + migration + indexes.
3. `ConcertScheduler` and `pastAfter`, with the frozen-clock boundary tests (AC-11.3) written before
   the filter that depends on them.
4. `ConcertInput`/`ConcertOutput` DTOs, state processor/provider, `POST` and item `GET`.
5. `ConcertOwnerExtension` + `ConcertVoter`, then the ownership isolation tests (AC-11.2). Confirm
   the 404 body matches the missing-id body byte for byte.
6. Collection `GET`: pagination, `ConcertStatusFilter`, `OrderFilter`, `BandNameFilter`.
7. `PATCH` (including lineup replacement) and `DELETE`, with the band-survival test.
8. Validation sweep against US-9; verify the RFC 7807 shape rather than assuming it.
9. Regenerate `frontend/api/`, run the frontend typecheck, and verify against the running stack
   (`docker compose up`) — not only the test suite (`CLAUDE.md`).
10. Documentation sweep (`/doc-check`).

## Out of Scope

| Not in this feature | Why / where it goes |
|---|---|
| **setlist.fm lookup, band verification, `setlistfmMbid` population** | Prompt 09. The nullable column exists here so that prompt is a migration-free change; nothing reads or writes it now |
| **Playlists and anything provider-shaped** | Prompts 10, 14–18. No `Playlist`, no `StreamingAccount`, no reference to a provider anywhere in this branch (`CLAUDE.md`'s streaming-port rule is trivially satisfied by not touching it) |
| **Notes and reviews as a feature** | Prompt 20. One plain `note` column here is enough (D-30) |
| **Any UI** | Prompt 07 builds the concert screens; prompt 06 designs them. This branch touches `frontend/` only to regenerate `frontend/api/` |
| **Venue as an entity, venue autocomplete, geocoding, maps** | Prompt 24. D-26 makes the promotion cheap |
| **Band pages, band photos, band metadata** | Prompt 24 |
| **Sharing a concert with another user, public concert URLs** | Prompt 21. Everything here is strictly single-owner (US-7); shared visibility is a different authorization model and deserves its own spec |
| **Backoffice CRUD for concerts and bands** | Prompt 08. It is not part of the API contract (`CLAUDE.md`) |
| **Attendance/ratings, "I actually went", companions, ticket images** | No demand signal; each is a separate model decision |
| **Bulk import (from a ticketing service, CSV, Songkick)** | A separate feature with its own ingestion, dedup and rate-limit questions |
| **Soft delete / trash / undo** | AC-6.5. Add it when a user asks, with a filter that respects it |
| **Full-text search, fuzzy band matching** | US-4's normalized substring match is enough for a personal-scale collection. Revisit if a user's list makes it slow |
| **Merging or splitting `Band` rows after a bad dedup** | An admin concern (prompt 08) once prompt 09 gives us a confident identity to merge *toward* |

## Dependencies

**Must be true before implementation begins**

| Dependency | Owner | Status |
|---|---|---|
| Prompt 01 merged — Symfony 8.1 / API Platform 4.3 skeleton, layer directories, PHPStan L9, backend CI | `backend-engineer` | **Met** |
| Prompt 04 merged — `User` entity, JWT firewall, `IS_AUTHENTICATED_FULLY` available to voters | `backend-engineer` | **Met** (PR #4) |
| The auth spec's ownership groundwork — a security token carrying `sub`, resolvable to a `User` | Prompt 04 | **Met** (D-18/D-22) |
| PostgreSQL with `TIMESTAMPTZ` and `unaccent`-free normalization done in PHP | Prompt 00 | **Met** — normalization is a PHP service (D-25), so no Postgres extension is required |
| Doctrine migrations wired and runnable in the container | Prompt 01 | **Met** |
| A frozen-clock mechanism in tests (`symfony/clock` `MockClock`) | **This branch** | **To verify** — required by AC-11.3; if `symfony/clock` is not already a dependency, add it and inject `ClockInterface` rather than calling `new \DateTimeImmutable()` anywhere in the domain |
| `openapi-typescript` generation script working against the live spec | Prompt 03 | **Met** — used in prompt 04 |
| Decision on whether `GET /api/bands` typeahead ships here | **This spec / approval** | **Open** — AC-8.7 leaves it optional; the default is *not* to ship it |
| New environment variables | — | **None expected.** If one appears, `docs/env-vars.md` **and** `.env.example` change together |

**Depended on by**

- **Prompt 07 (concert tracker UI)** — consumes every endpoint here through the generated client.
- **Prompt 09 (setlist.fm integration)** — attaches identifiers to `Band` and caches setlists per
  band + event date; D-25's normalization is what it will replace.
- **Prompts 14–17 (playlists)** — `Playlist` hangs off `Concert` (`docs/architecture.md` §10).
- **Prompt 20 (notes and reviews)** — promotes D-30's `note` column.
- **Prompt 21 (sharing)** — relaxes US-7's strict single-owner model; it must be able to see exactly
  where ownership is enforced, which D-27 makes obvious.
- **Prompt 22 (entitlement/quota seam)** — a per-user concert count is the most likely first quota.

**Assumptions** *(labelled as assumptions, not verified facts)*

- Users record concerts at personal scale (tens to low hundreds), so offset pagination and a
  substring search are adequate; no cursor pagination is needed yet.
- The client can supply a sensible IANA timezone (device timezone via `Intl.DateTimeFormat`) as the
  default for `timezone`, so the field is required by the API without being a burden in the UI.
- API Platform 4.3's merge-patch support behaves as documented for a DTO-backed resource with a
  collection-valued property (the lineup). If it does not, R-5 applies.
- A concert's date is known when it is recorded. Concerts with a "TBA" date are not modelled; if
  that turns out to matter, it is a nullable-date change and a new status value, not a redesign.
- Nobody needs to record the same band twice in one lineup (AC-1.6) — a band playing two sets at a
  festival is one lineup entry.

## Risks

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R-1 | **The timezone model (D-24) is wrong in a way that only shows up with real users** — e.g. people expect a concert to stay "upcoming" during the show itself, or expect their own timezone to drive it | High — subtle, irritating, and expensive to change once prompt 07's UI and prompt 09's setlist matching depend on the semantics | The rule is stated explicitly in AC-3.6, tested at both boundary instants and cross-timezone, and the API returns `date` + `timezone` + `status` so a client can disagree without a server change. `pastAfter` is derived, so changing the rule is a recompute migration, not a data loss |
| R-2 | **Band dedup merges two genuinely different bands** (D-25's accepted false-merge), and prompt 09 then attaches one setlist.fm identity to both | Medium now, higher after prompt 09 — a user sees setlists from a band they did not see | `normalizedName` is a stored column derived by a service, so it can be re-derived. Prompt 09 should treat a mismatch between setlist.fm identity and a local band as a **split signal**, and prompt 08 should provide the admin merge/split tool. Record this hand-off explicitly in the PR |
| R-3 | **`pastAfter` drifts out of sync with `date`/`timezone`** through a code path that writes the entity without the scheduler (a fixture, a console command, a future admin form) | Medium — silently wrong upcoming/past for some rows | Derive it in a Doctrine lifecycle listener (or the entity's own setters) rather than only in the state processor, so *no* write path can skip it; test by mutating through the repository directly and asserting the column |
| R-4 | **Ownership leaks through a path the extension does not cover** — a subresource, a future join, an admin query, or a filter applied before the extension | High — this is the failure that ends trust in the product | The extension covers item *and* collection (AC-7.2, AC-7.6), the voter is the second gate (AC-7.5), and the isolation tests run per verb. A `devops-security-engineer` review of the diff before merge is recommended, since this is the first user-scoped resource and later resources will copy it |
| R-5 | **API Platform's merge-patch does not cleanly replace a collection-valued DTO property** (the lineup), producing merges or duplicates instead of replacement | Medium — AC-5.2 fails or, worse, half-works | Write the lineup-replacement test early (step 7). If merge-patch fights the model, fall back to `PUT` semantics for concerts, or a dedicated `PATCH /api/concerts/{id}/lineup` operation — and record the change as a decision rather than leaving the shape ambiguous |
| R-6 | **The band-name filter's join produces duplicate concerts or a slow query** when several bands match | Low–medium | `DISTINCT`/`EXISTS` subquery rather than a naive join (AC-4.3), index on `band.normalized_name`, and a test with a multi-match lineup |
| R-7 | **Scope creep into prompt 09** — "while we are here, look the band up on setlist.fm to confirm it exists" | Medium — it would put a rate-limited external call (1,440/day *for the whole application*, `CLAUDE.md`) in the concert write path, which is exactly the wrong place for it | The Out of Scope table is binding. No HTTP client of any kind is added in this branch; CI reaches no external network (D-2) |
| R-8 | **Money-as-minor-units confuses a future client** into treating `4500` as €4500 | Low but embarrassing | Documented in the OpenAPI field description, exposed only as `{ amount, currency }` (never a bare number), and formatted with `Intl.NumberFormat` on the client from day one in prompt 07 |
| R-9 | **The generated client is regenerated but not committed**, so the frontend compiles locally and fails in CI (or vice versa) | Low | AC-10.2 makes regeneration part of this branch, and the frontend typecheck runs in CI |

## Documentation to update in this branch

Per `CLAUDE.md`'s mandatory documentation check (run `/doc-check` before committing):

- **`docs/architecture.md`** — record **D-24**–**D-31** in the Decisions section; confirm §10's data
  model sketch against what was actually built (add `ConcertBand.billingOrder` and the derived
  `pastAfter` if the sketch is now less precise than the code); add a line to §11 noting that
  concerts are the first user-scoped resource and that ownership is enforced by query extension plus
  voter, returning 404 rather than 403.
- **`CLAUDE.md`** — the domain glossary already defines Concert, Setlist, Song, Track, Playlist and
  Provider. Add **Band**, **Lineup** and **billing order** if the glossary reads as incomplete
  without them; consider a Setlistify-specific rule that **user-scoped resources return 404, never
  403** — it is a project-wide convention from here on, not a detail of this feature.
- **`docs/env-vars.md`** and **`.env.example`** — no change expected; if a variable appears, both
  files or neither.
- **No endpoint list in any README** — the regenerated OpenAPI document is the single source of
  truth.
- **Root `README.md`** — no change expected (no new service, port or command).
- **`frontend/README.md`** — only if the regenerated client changes how `frontend/api/` is described.
- **`docs/external-apis.md`** — no change; nothing external is called here (R-7).

---

## Review

**This spec needs your approval before implementation begins.**

Eight decisions are made on your behalf (**D-24**–**D-31**), three of which resolve the open
questions the prompt raised. The first four get materially more expensive to reverse once prompts
07, 09 and 14 build on them:

1. **D-24** *(prompt's timezone question)* — a concert is a **local calendar date + IANA timezone**,
   and is `upcoming` until the end of **its own** local day, not the viewer's. Enforced by a derived
   `pastAfter` instant so the filter is one indexed comparison. This is the decision worth the most
   of your attention: it sets what "tonight" means everywhere in the product.
2. **D-25** *(prompt's normalization question)* — simple case/diacritic/article-insensitive
   normalization, deliberately **preferring false merges over false splits**, with prompt 09 as the
   place identity gets real and prompt 08 as the place merges get fixed.
3. **D-26** *(prompt's venue question)* — venue as an **embeddable value object** serialized as a
   nested `venue` object, so promoting it to an entity in prompt 24 is additive, not breaking.
4. **D-27** — ownership via **query extension (404) plus voter**, establishing the pattern every
   later user-scoped resource copies.
5. **D-28** — ticket price as **integer minor units + ISO 4217**, never a float.
6. **D-29** — `Concert` is never a writable entity resource; DTOs bound the payload (continuing
   D-22).
7. **D-30** — `note` is plain text with no rendering contract; prompt 20 owns notes properly.
8. **D-31** — bounds (lineup ≤ 30, note ≤ 2000, page ≤ 100, date within [1900, +5y]) set now rather
   than after abuse.

**One question is deliberately left to you:** AC-8.7 — should a read-only `GET /api/bands?q=`
typeahead ship in this branch, or wait for prompt 07 to ask for it? The default in this spec is
**not to ship it**.

Reply with approval, or with the decisions you want changed, and the
`feature/concert-domain-api` branch can be created.
