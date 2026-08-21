# Concert Tracker UI

| | |
|---|---|
| **Spec ID** | `2026-08-21-concert-tracker-ui` |
| **Backlog prompt** | `docs/prompts/07-concert-tracker-ui.md` |
| **Command** | `/feature concert-tracker-ui` |
| **Primary agent** | `frontend-engineer` (frontend only; the backend is consumed, not changed) |
| **Branch** | `feature/concert-tracker-ui` |
| **Depends on** | `03` — frontend skeleton (merged) · `05` — concert domain and API (merged, PR #5) · `06` — concert screens design (merged, `a75b2a7`) |
| **Status** | **Draft — awaiting approval** |

---

## Overview

The backend knows what a concert is (prompt 05) and the designers know what it looks like (prompt
06). The app itself still opens on a health screen. This feature closes that gap: it implements
`docs/design/canvas/screens/` against the endpoints already published in `frontend/api/schema.d.ts`,
so a user can **add a concert with several bands, browse their upcoming and past concerts, open one,
edit it and delete it** — on web, iOS and Android, from one codebase.

This is the prompt that makes Setlistify a product. Everything before it was scaffolding: a
monorepo, a skeleton, an auth flow, a domain, a design. After this branch, someone can open the app
and get value from it before a single playlist exists. Every later prompt — setlist.fm lookup (09),
playlist generation (14–17), the player (19), notes (20), sharing (21) — attaches to a screen that
this branch builds.

Four commitments shape the implementation:

1. **The design is implemented, not reinterpreted.** `docs/design/canvas/screens/` already answers
   the layout questions: a single "Your concerts" scroll with **Upcoming** and **Past** sections
   (`Main.dc.html`), a phone bottom tab bar with *Concerts* and *Account* (`NavShell.dc.html`), a
   concert detail with labelled *Playlist* / *Your note* / *Share* regions marked "Coming later"
   (`ConcertDetail.dc.html`), and five new components already drawn (`NewComponents.dc.html`: band
   entry row, disclosure trigger, reserved-section placeholder, skeleton card, bottom tab bar).
   Divergence from the canvas is a decision to record, not a detail to improvise.
2. **The API contract is consumed, never restated.** `frontend/api/schema.d.ts` is generated and
   committed (D-10); every request and response type in this branch is derived from
   `components["schemas"]["Concert.ConcertOutput.jsonld"]` and friends. A hand-written interface
   describing a concert is a defect, not a shortcut (`CLAUDE.md`, API Contract).
3. **The server is authoritative, the client is optimistic.** Adding a concert should feel like
   jotting it down — so the card appears immediately. But band deduplication happens server-side
   (D-25): the `Band` id and even the stored name that come back may not be what the client
   assumed. The optimistic row is a placeholder to be **replaced by the response**, never a value to
   be trusted (D-33).
4. **Ownership is invisible, by design.** The API returns **404, never 403**, for another user's
   concert (D-27). The client must not undo that: a 404 on a concert detail renders the ordinary
   "this concert doesn't exist" state, identical for a deleted id, a typo'd id and someone else's
   id. There is no "you don't have access" copy anywhere in this branch.

This feature ships **no setlist lookup** (prompt 09 — band entry stays free text), **no playlist**
(prompts 14–17), **no notes UI** (prompt 20) and **no sharing** (prompt 21). It ships the three
screens those features will grow into.

## Goals

| Goal | Success looks like |
|---|---|
| The core loop works end to end | create → list → detail → edit → delete, on web, iOS and Android, against the real API |
| A lineup keeps its shape in the UI | A concert entered as `[headliner, support, opener]` shows in that order on the card, the detail and the edit form |
| Adding a concert feels like jotting it down | Date + one band is enough to save; venue, price and times stay collapsed until asked for |
| A new user is not shown an empty box | The designed empty state from `EmptyState.dc.html`, with one obvious first action |
| Nothing shows a raw error string | Every failure resolves to a designed state: loading, empty, degraded, error, offline |
| Server validation lands on the right field | RFC 7807 `violations[].propertyPath` maps to the input that caused it, including per-band lineup paths |
| Dates are correct everywhere | A concert renders by its own `date` + `timezone` (D-24), formatted in the user's locale, identical in Madrid and Auckland |
| The contract holds at build time | No hand-written API types; `npm run typecheck`, `lint` and `test` green in CI |

## User Stories

### US-1 — See my concerts, upcoming and past

> As a user with concerts saved, I want to open the app and see what's coming up and what I've
> already been to, so that the app is worth opening even when I'm not adding anything.

- **AC-1.1** The Concerts tab renders `GET /api/concerts` as one vertically scrolling list with two
  section headers, **Upcoming** first and **Past** second, matching `Main.dc.html` (D-32).
- **AC-1.2** Upcoming concerts sort by date ascending (soonest first); past concerts sort descending
  (most recent first). This is the API default per section, requested explicitly via `status` +
  `order[date]` rather than relying on an unstated default.
- **AC-1.3** Each card shows the lineup in **billing order** with the headliner visually primary,
  the date in the user's locale, and the venue when present — the concert card from the canvas.
- **AC-1.4** While the first page loads, skeleton cards render (`NewComponents.dc.html` — skeleton
  card); no spinner-on-blank-screen, no layout shift when real data arrives.
- **AC-1.5** Pull-to-refresh on phone (and a visible refresh affordance on desktop where pull is
  unavailable) refetches both sections.
- **AC-1.6** Reaching the end of a section loads the next page from the Hydra `view.next` link via
  `useInfiniteQuery`; page size is 20 (D-41). Scrolling never loses position.
- **AC-1.7** A section with no rows shows its own inline empty line ("No past concerts yet",
  `States.dc.html`) rather than disappearing — so a user with only upcoming concerts still sees that
  Past exists.
- **AC-1.8** A list-level failure renders the designed error state with **Try again**, not a message
  from the server.

### US-2 — Start from nothing

> As a brand-new user with no concerts, I want the first screen to tell me what this app is for and
> give me one thing to do, so that I don't bounce off an empty list.

- **AC-2.1** When the collection is empty (not loading, not errored, zero members across both
  statuses), the screen renders `EmptyState.dc.html` in full — not a generic `EmptyState` with a
  swapped label.
- **AC-2.2** The empty state's primary action opens Add concert.
- **AC-2.3** The empty state is distinguishable from the error state and the offline state; each has
  its own copy and its own action (`Try again`, `Back to concerts`).
- **AC-2.4** After the user's first successful create, the empty state is gone without a manual
  refresh.

### US-3 — Add a concert with several bands, quickly

> As a user who just bought a ticket, I want to save the concert in a few seconds on my phone, so
> that recording it never feels like paperwork.

- **AC-3.1** The Add concert screen implements `AddConcert.dc.html`: band entry, date, and
  progressive disclosure for venue, ticket price and doors/start times.
- **AC-3.2** Bands are entered as **free text** (prompt 09 adds search). Adding a band appends a
  band entry row; rows can be reordered and removed. Row order **is** billing order — index 0 is the
  headliner — and this is stated in the UI, not implied.
- **AC-3.3** At least one band is required; up to 30 are accepted (D-31). Adding a second band takes
  one tap; adding an eighth still works without the form becoming unusable.
- **AC-3.4** The date field uses one `DateField` component that branches per platform internally
  (D-34); the rest of the form is platform-neutral.
- **AC-3.5** `timezone` defaults to the device's IANA zone and is always sent on create (D-35). The
  user is not asked for it in the default flow.
- **AC-3.6** Venue, ticket price and doors/start are behind disclosure triggers
  (`NewComponents.dc.html`) and are collapsed by default; a concert saves with date + one band only.
- **AC-3.7** Ticket price is entered as a decimal amount plus a currency and sent as
  `{ amount: <minor units>, currency }` (D-28, D-38). `12,50` and `12.50` both yield `1250`.
- **AC-3.8** Times are entered as local wall-clock `HH:MM` in the concert's timezone and sent as
  such — never converted to UTC on the client.
- **AC-3.9** Save is disabled only while a submission is in flight, never as a substitute for
  showing validation errors.

### US-4 — The concert appears immediately, and is still correct

> As a user adding a concert, I want the app to feel instant, but I never want to see a band name or
> a lineup that isn't what the server actually stored.

- **AC-4.1** On submit, an optimistic card is inserted into the correct section, keyed by a
  client-generated temporary id, and the user is returned to the list.
- **AC-4.2** On `201`, the optimistic entry is **replaced wholesale** by the response body — the
  server's `Concert.ConcertOutput`, including its `lineup[].band.id` and `band.name` values, which
  may differ from what was typed because of dedup (D-25, D-33). No field of the optimistic value
  survives reconciliation.
- **AC-4.3** A test proves the reconciliation: submitting `"the beatles"` when the server returns
  `Beatles` (id 7) leaves the list showing `Beatles`, not `the beatles`.
- **AC-4.4** On failure (4xx, 5xx or network), the optimistic entry is removed, the user is returned
  to the form **with their input intact**, and the failure is explained (US-8, US-10).
- **AC-4.5** An optimistic card is visually marked as pending and is not tappable through to a
  detail screen (it has no server id yet).

### US-5 — Open a concert

> As a user, I want to open a concert and see everything I recorded about it, plus a clear signal of
> what the app will add later.

- **AC-5.1** The detail screen implements `ConcertDetail.dc.html`: lineup with the headliner
  labelled, date, venue and ticket price, plus a status treatment distinguishing upcoming from past.
- **AC-5.2** The **Playlist**, **Your note** and **Share** regions are present and rendered with the
  reserved-section placeholder ("Coming later"), so prompts 19–21 add content without a redesign.
- **AC-5.3** Absent optional fields (no venue, no price, no times) collapse cleanly; the screen
  never shows an empty label or a dash-placeholder that looks like a bug.
- **AC-5.4** Ticket price renders with `Intl.NumberFormat` from minor units + ISO 4217 code (D-38).
- **AC-5.5** The date renders from the concert's own `date` and `timezone` (D-35), formatted in the
  user's locale — a concert on 2026-09-05 in `Europe/Madrid` reads as 5 September 2026 for a viewer
  in Auckland, never 6 September.
- **AC-5.6** A `404` renders the ordinary "not found" state with **Back to concerts** — the same
  state for a deleted id, an unknown id and another user's id (US-11).

### US-6 — Correct a concert

> As a user who typed the venue wrong or forgot the support act, I want to fix a concert I already
> saved.

- **AC-6.1** Edit opens the same form as Add, pre-filled from the concert, per `EditDelete.dc.html`.
- **AC-6.2** Saving sends `PATCH /api/concerts/{id}` as JSON merge-patch using the generated
  `Concert.ConcertPatchInput.jsonMergePatch` type.
- **AC-6.3** Editing the lineup replaces it: removing a band, adding one, and reordering all persist
  and read back in the new billing order.
- **AC-6.4** Clearing an optional field (venue, price, note, times) actually clears it server-side
  rather than leaving the old value.
- **AC-6.5** On success, the detail and list views both reflect the change without a manual refresh.
- **AC-6.6** Leaving the form with unsaved changes asks for confirmation.

### US-7 — Delete a concert

> As a user who added a concert by mistake, I want to remove it, and I don't want to remove one by
> mistake.

- **AC-7.1** Delete is available from the detail screen (and/or the edit screen) per
  `EditDelete.dc.html`, and always requires an explicit destructive confirmation naming the concert.
- **AC-7.2** Confirming issues `DELETE /api/concerts/{id}`; on success the user lands back on the
  list with the concert gone.
- **AC-7.3** Deletion is permanent — there is no undo affordance, because the API has no soft delete
  (spec 05, AC-6.5). The confirmation copy says so rather than implying recoverability (D-40).
- **AC-7.4** A failed delete leaves the concert visibly present and explains the failure; the list
  never shows a row that no longer exists, nor hides one that still does.
- **AC-7.5** Deleting a concert does not affect other concerts sharing the same band.

### US-8 — Invalid input is explained where it happened

> As a user who typed something the server won't accept, I want to be told which field is wrong and
> why, right next to it.

- **AC-8.1** Client-side validation mirrors the server's documented bounds (D-31): at least 1 and at
  most 30 bands, band name 1–120 characters, note ≤ 2000, date within [1900-01-01, now + 5 years].
- **AC-8.2** Client validation is **advisory** — it prevents obvious mistakes but never suppresses
  or overrides a server response (D-36).
- **AC-8.3** A `422` `ConstraintViolation` payload is parsed and each `violations[].propertyPath` is
  mapped to its field, including indexed lineup paths (`lineup[2].band.name` highlights the third
  band row).
- **AC-8.4** A violation whose path maps to no visible field appears in a form-level summary. No
  code path renders `detail`, `title` or a raw JSON body directly to the user.
- **AC-8.5** A `400` (malformed body) and a `422` (validation) produce different, honest messages.
- **AC-8.6** Fixing the flagged field clears its error; resubmitting does not require re-entering
  anything.

### US-9 — One app, the right shape on each device

> As a user on a phone or a laptop, I want navigation that fits the device I'm holding.

- **AC-9.1** The authenticated shell (`app/(app)/`) exposes **Concerts** and **Account** per
  `NavShell.dc.html`, as a bottom tab bar on phone.
- **AC-9.2** At desktop width the same routes render with the desktop layout from the canvas
  (persistent navigation rather than a bottom bar), driven by a **width breakpoint in one layout**,
  not by a platform fork (D-39, honouring spec 03's AC-1.8).
- **AC-9.3** Deep links work: a concert detail URL on web loads directly, and the back affordance
  returns to the list rather than dead-ending.
- **AC-9.4** Touch targets stay ≥ 44×44 and the canvas's WCAG AA contrast pairs hold in light and
  dark mode.
- **AC-9.5** Any component the canvas introduces (`NewComponents.dc.html`) is added to
  `frontend/components/` and to the component inventory — not inlined into a screen.

### US-10 — The app degrades honestly offline

> As a user on a train with no signal, I want to still read the concerts I've already loaded, and to
> be told plainly when something can't be saved.

- **AC-10.1** With no connectivity, previously fetched lists and details render from the TanStack
  Query cache, with the designed degraded/offline treatment (D-37).
- **AC-10.2** A write attempted offline fails fast with a clear, recoverable message and the user's
  input preserved — it is **not** silently queued for later.
- **AC-10.3** Retry after connectivity returns succeeds without an app restart.
- **AC-10.4** Offline is distinguishable from server error in the UI; the two never share copy.

### US-11 — Another user's concert simply does not exist

> As a user, I want the app to never reveal — even accidentally — that a concert id belongs to
> someone else.

- **AC-11.1** A `404` from any concert endpoint renders the ordinary not-found state (AC-5.6).
- **AC-11.2** No string in this branch says "forbidden", "not allowed", "not yours" or "permission"
  in relation to a concert.
- **AC-11.3** A `403` (which the concert endpoints are not expected to return for ownership, D-27)
  is treated as a session problem and routed to the existing auth handling, never rendered as a
  concert-level message.
- **AC-11.4** A test asserts that a 404 on detail produces exactly the same rendered output as a 404
  for an id that never existed.

### US-12 — The contract is generated and the branch is green

> As the team, I want a breaking API change to be a compile error, and this feature's behaviour to
> be covered by tests.

- **AC-12.1** `npm run generate:api` is run first; `frontend/api/` is regenerated and committed if
  it moved, and the CI staleness check (D-10) passes.
- **AC-12.2** No file outside `frontend/api/` declares a concert, band, lineup, venue or money
  request/response shape by hand. Types are imported from the generated `components`/`operations`.
- **AC-12.3** All requests go through the existing `lib/api/` client wrapper (spec 03, D-11/AC-7.7);
  nothing calls `fetch` directly.
- **AC-12.4** Server state uses TanStack Query (D-12) with query keys namespaced per concert
  resource; no new global client-state library is introduced.
- **AC-12.5** Tests cover: list rendering in **loading / empty / populated / error** states; the
  create flow including optimistic insert and reconciliation (AC-4.3); rollback on failure; RFC 7807
  field-error display including an indexed lineup path; the 404 not-found equivalence (AC-11.4); and
  date rendering across a differing device timezone.
- **AC-12.6** Tests stub `global.fetch` per D-14 — no MSW, no live backend in CI (D-2).
- **AC-12.7** `lint`, `typecheck` and `test` are green in CI.

## Technical Approach

### Frontend (`frontend/`) — the only sub-project changed

```
app/(app)/
  _layout.tsx              tab shell (phone) / desktop layout, one breakpoint (D-39)
  concerts/index.tsx       list — sections, skeletons, infinite scroll, empty state
  concerts/new.tsx         add concert
  concerts/[id]/index.tsx  detail — lineup, details, reserved regions
  concerts/[id]/edit.tsx   edit + delete
components/
  concert/ConcertCard.tsx, LineupList.tsx, BandEntryRow.tsx,
  concert/DisclosureSection.tsx, ReservedSection.tsx, SkeletonCard.tsx
  DateField.native.tsx / DateField.web.tsx   the single platform fork (D-34)
lib/concerts/
  queries.ts     useConcerts (infinite), useConcert, useCreate/Update/DeleteConcert
  mapping.ts     form model ⇄ generated DTOs (money minor units, HH:MM, timezone)
  validation.ts  client mirror of D-31 bounds
  violations.ts  RFC 7807 propertyPath → form field mapping (D-36)
```

The backend is **not** modified. If a gap in the API is discovered, it is raised as a change to spec
05 rather than patched around in the client (see R-6).

### Decisions

Numbered from **D-32** onward; `D-1`–`D-3` are project-wide (`docs/architecture.md`), `D-4`–`D-9`
belong to prompt 01, `D-10`–`D-17` to prompt 03, `D-18`–`D-23` to prompt 04 and `D-24`–`D-31` to
prompt 05.

**D-32 — The concert list is one scroll with Upcoming/Past sections, not two tabs.** The prompt
leaves this open; the canvas already draws sections (`Main.dc.html`), and sections keep past
concerts — the emotional payload of the product — visible without a deliberate act. Tabs scale
better at hundreds of concerts, but that scale is speculative today and the escape hatch is cheap:
the API already supports `status` and `band` filters, so the answer at scale is search and filtering
inside one list, not a structural split. Sections are implemented with the API's `status` filter
issued as **two independent paginated queries**, so switching to tabs later is a layout change, not
a data-layer rewrite.

**D-33 — Optimistic creation is reconciled from the response, never merged with it.** The server
deduplicates bands (D-25) and may return a different `Band` id and a differently-cased `name` than
the client sent. The optimistic entry therefore carries a temporary client id, is marked pending,
and on `201` is **discarded and replaced** by the response payload. Merging the two — keeping the
typed name because it "looks nicer" — would make the client's view of a band diverge from the
server's identity, which is exactly what prompt 09 will attach setlist.fm identifiers to.

**D-34 — All date/time platform branching lives in one `DateField` component.** iOS, Android and web
date input genuinely differ and no cross-platform picker is worth a dependency here (D-15's
web-support gate). Spec 03's AC-1.8 forbade platform forks; this branch takes exactly one, contained
in `DateField.native.tsx` / `DateField.web.tsx` behind a single shared prop contract. No screen
imports `Platform` directly.

**D-35 — The client sends the device's IANA timezone and renders concerts in the concert's own
zone.** `Intl.DateTimeFormat().resolvedOptions().timeZone` supplies the default `timezone` on create
(spec 05 assumed exactly this). On read, a concert is formatted from its `date` + `timezone` and is
never converted into the viewer's zone — the viewer's zone changes when they travel; the concert's
does not (D-24).

**D-36 — Client validation mirrors the server's bounds but is advisory; the server is
authoritative.** The client copy of D-31's bounds exists to save a round trip, not to define
correctness. Every submission renders whatever violations come back, even where the client believed
the input valid — so a bound tightened server-side degrades to a server-side error message, never to
input the user cannot submit and cannot understand.

**D-37 — Reads fall back to cache offline; writes fail rather than queue.** No offline write queue,
no background sync in this branch. A queued write would need conflict handling, a durable outbox and
a story for a concert created twice — all of which deserve their own spec. Offline reads come free
from TanStack Query's cache (D-12) with a persisted cache only if it costs nothing extra.

**D-38 — Money and dates are formatted with `Intl`, converted in exactly one place.** `lib/concerts/
mapping.ts` owns decimal ⇄ minor-units (D-28) and `HH:MM` handling. No component does arithmetic on
a price and no component parses a date string.

**D-39 — Phone/desktop layout is a width breakpoint in one layout file, not a platform fork.** A
tablet, a large phone and a browser window that gets resized all have to work; branching on
`Platform.OS` gets each of those wrong. The tab bar and the desktop navigation render from the same
route tree.

**D-40 — Delete is permanent and the confirmation says so.** The API hard-deletes (spec 05,
AC-6.5), so an "Undo" toast would be a lie. The confirmation names the concert and uses destructive
styling; there is no trash, no restore.

**D-41 — Infinite scroll over Hydra `view.next`, page size 20.** `useInfiniteQuery` with the
collection's own `next` link as the cursor, rather than computing page numbers client-side, so the
client never disagrees with the server about how many pages exist. 20 fits well over a phone screen
of cards and stays under the API's cap of 100 (D-31).

### Suggested implementation order

1. Regenerate `frontend/api/` and confirm it is current (AC-12.1) before writing any client code.
2. `lib/concerts/` — query hooks, DTO mapping, validation, violation mapping — with tests, no UI.
3. Navigation shell and the Concerts route tree (US-9).
4. List: skeleton → populated → empty → error → offline (US-1, US-2, US-10).
5. Detail, including the reserved regions and the 404 state (US-5, US-11).
6. Add concert: form, band rows, `DateField`, disclosure (US-3).
7. Optimistic create and reconciliation (US-4) — write AC-4.3's test before the happy path.
8. Edit and delete (US-6, US-7).
9. Validation error rendering end-to-end against the real backend (US-8).
10. Cross-platform pass: web, iOS, Android, light and dark, phone and desktop width.

## Out of Scope

| Not in this feature | Why / where it goes |
|---|---|
| **Band search / autocomplete against setlist.fm** | Prompt 09. Free-text band entry is correct now; the API accepts it and dedups server-side (D-25) |
| **Setlists, playlist generation, provider linking, playback** | Prompts 10, 14–19. The detail screen reserves the region and renders it as "Coming later" (AC-5.2) — nothing more |
| **Notes and reviews as a feature** | Prompt 20. The `note` field may be edited as plain text where the form already covers it, but there is no notes UI, no rendering contract, no Markdown (D-30) |
| **Sharing, public concert URLs, link previews** | Prompt 21. The Share region is a placeholder (AC-5.2); SPA/SEO constraints stay as recorded in D-17 |
| **Offline write queue, background sync, conflict resolution** | D-37. A separate feature with its own durability and duplicate-create questions |
| **Venue autocomplete, maps, geocoding** | Prompt 24. Venue is free-text fields matching the embeddable (D-26) |
| **Search and filtering UI over the concert list** | The API supports `band` and `status`; no search surface is designed yet. Revisit with D-32 if lists get long |
| **Bulk import, ticket photos, companions, ratings** | No demand signal; each is its own model decision (mirrors spec 05's Out of Scope) |
| **Any backend change** | This branch consumes prompt 05's API as shipped. A required change re-opens spec 05 (R-6) |
| **Backoffice screens** | Prompt 08; EasyAdmin supplies its own UI and is not part of the contract |
| **Push notifications / concert reminders** | Not designed, not specified, and it introduces a whole delivery infrastructure |

## Dependencies

**Must be true before implementation begins**

| Dependency | Owner | Status |
|---|---|---|
| Prompt 03 merged — Expo Router app, theme, base components, `lib/api/` wrapper, TanStack Query, Jest | `frontend-engineer` | **Met** |
| Prompt 04 merged — session, route guards, `(app)` authenticated group | `frontend-engineer` | **Met** |
| Prompt 05 merged — `/api/concerts` collection, item, POST, PATCH, DELETE | `backend-engineer` | **Met** (PR #5, `docs/specs/2026-08-21-concert-domain-api.md`) |
| Prompt 06 merged — concert screens design canvas | design canvas | **Met** — `docs/design/canvas/screens/` (`Main`, `EmptyState`, `AddConcert`, `ConcertDetail`, `EditDelete`, `NavShell`, `States`, `NewComponents`) at commit `a75b2a7` |
| Prompt 02 tokens and components | design canvas | **Met** — `docs/design/canvas/`, consumed via `frontend/theme/` and `frontend/components/` |
| Generated client covering the concert endpoints | Prompt 05 | **Met** — `frontend/api/schema.d.ts` exposes `Concert.ConcertInput`, `Concert.ConcertOutput.jsonld`, `Concert.ConcertPatchInput.jsonMergePatch`, `LineupEntryInput/Output`, `VenueData`, `MoneyData`, `ConstraintViolation`. Regenerate first regardless (AC-12.1) |
| Spec 05's decisions D-24–D-31 approved | User | **To confirm** — this spec's D-33, D-35, D-36, D-38 and D-40 are direct consequences of D-25, D-24, D-31, D-28 and AC-6.5. If any of those changes, revisit here |
| A cross-platform date input strategy | **This branch** | **Open** — D-34 contains the fork; whether a vetted dependency clears D-15's web-support gate is decided during step 6 |
| Components deferred by D-16 (tabs, sheets, toasts, date inputs) | **This branch** | **Expected** — prompt 07 is exactly the "first real consumer" D-16 was waiting for; they are built here and added to the inventory (AC-9.5) |
| New environment variables | — | **None expected.** If one appears, `docs/env-vars.md` **and** `frontend/.env.example` change together |

**Depended on by**

- **Prompt 09 (setlist.fm)** — replaces free-text band entry with search against a verified band.
- **Prompt 16 (playlist fast-mode UI)** and **19 (player embed)** — fill the reserved Playlist region.
- **Prompt 20 (notes and reviews)** — fills the reserved Your-note region.
- **Prompt 21 (sharing)** — fills the reserved Share region and revisits D-17's SPA/SEO constraint.
- **Prompt 22 (entitlement/quota)** — a per-user concert count is the likely first quota, and this
  is the surface that must explain it.

**Assumptions** *(labelled as assumptions, not verified facts)*

- The canvas screens are complete enough to implement without a further design round; ambiguities
  are resolved in favour of the canvas and recorded in the PR.
- Users have tens to low hundreds of concerts, so two paginated section queries at page size 20 are
  adequate (D-32, D-41) and no client-side virtualization beyond the list primitive is needed.
- The device's IANA timezone is a good default for a concert's timezone (D-35) — true for the common
  case of buying a ticket for a show near you; someone booking a trip abroad will need to change it,
  and the field remains editable in the disclosure section.
- The API's Hydra collection responses carry a usable `view.next`; if pagination metadata differs
  from expectation, D-41's cursor source changes but the UX does not.
- `PATCH` merge-patch replaces the lineup wholesale (spec 05's R-5). If it does not, AC-6.3 fails and
  the fix belongs in spec 05, not in the client.

## Risks

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R-1 | **Optimistic create diverges from server truth** because of band dedup — the list shows a band name or id the server never stored | Medium–high; it silently corrupts the client's notion of band identity, which prompt 09 then builds setlist lookups on | D-33: replace, never merge. AC-4.3 makes the divergence case a test with a deliberately different server response, written before the happy path |
| R-2 | **The date picker fork spreads** — `Platform.OS` checks leak into screens as each platform's picker turns out to behave differently | Medium, compounding — spec 03's D-15/AC-1.8 exists precisely to stop this | D-34: one component, one prop contract, and a lint-visible rule that no screen imports `Platform`. If a dependency is adopted it must pass D-15's web-support gate first |
| R-3 | **Sections vs tabs is decided by implementation drift** rather than deliberately, and the two sections' pagination interacts badly (one section loading while the other refreshes) | Medium — a structural choice that is annoying to reverse once tests and layout assume it | D-32 decides it explicitly *and* implements it as two independent queries, so the layout can change without touching the data layer. Flag it in review if the canvas and D-32 ever disagree |
| R-4 | **Timezone rendering is subtly wrong** — a concert shifts a day for a traveling user, or the device timezone is captured at the wrong moment | High for trust; dates are the one thing a user will absolutely notice | D-35 formats from the concert's own zone and never converts. AC-12.5 requires a test with a device timezone that differs from the concert's, exercising the day boundary |
| R-5 | **RFC 7807 paths don't map to fields** — especially indexed lineup paths, or paths shaped differently than the client expects (`lineup[2].band.name` vs `lineup[2].name`) | Medium — the user gets a form-level blob instead of a highlighted field, which is exactly the failure US-8 exists to prevent | Verify the actual `propertyPath` strings against the running backend early (step 2), not from the schema alone. AC-8.4's summary fallback guarantees nothing is swallowed even when a path is unrecognised |
| R-6 | **A gap in the prompt-05 API is patched around in the client** — e.g. client-side sorting, filtering or joining because an endpoint doesn't quite fit | Medium — it moves domain logic into the client, against `CLAUDE.md`'s persistence rule, and hides the gap from prompt 09 | The backend is out of scope by construction. Any gap is raised as a spec-05 amendment and a separate backend change on its own branch |
| R-7 | **Scope creep into prompts 09/20/21** — "the Share button is right there", "band autocomplete is easy" | Medium — this prompt is already the largest frontend surface to date | The reserved regions render placeholders only (AC-5.2). The Out of Scope table is binding |
| R-8 | **Three-platform verification is skipped** and a regression ships on the platform nobody opened | Medium — the cost of a cross-platform codebase is paid only if all three are actually run | Step 10 is an explicit implementation step, and AC-12.7 keeps automated coverage honest; `docker compose up` plus the Expo client on each target per `CLAUDE.md`'s "verify against the real integration" rule |
| R-9 | **Offline behaviour is asserted but never exercised**, so the degraded state is dead code | Low–medium | AC-10.1–10.4 are testable with the fetch stub (D-14) rejecting; at least one offline read and one offline write test are required |

## Documentation to update in this branch

Per `CLAUDE.md`'s mandatory documentation check (run `/doc-check` before committing):

- **`docs/architecture.md`** — record **D-32**–**D-41** in the Decisions section; note that the
  frontend now consumes the concert domain and that the concert detail reserves regions for prompts
  19–21.
- **`frontend/README.md`** — the new route tree under `app/(app)/concerts/`, the `lib/concerts/`
  layer, the single sanctioned platform fork (D-34) and the components added to the inventory
  (AC-9.5). **No endpoint list** — the OpenAPI document remains the single source of truth.
- **Component inventory** (`frontend/components/` + the canvas's `NewComponents.dc.html`) — band
  entry row, disclosure trigger, reserved-section placeholder, skeleton card and tab bar are added
  back to the inventory, per prompt 02's rule.
- **`CLAUDE.md`** — no change expected; the glossary already covers Concert, Band, Lineup and
  billing order, and the 404-not-403 rule is already recorded.
- **`docs/env-vars.md` / `frontend/.env.example`** — no change expected; if a variable appears, both
  files or neither.
- **Root `README.md`** — no change expected (no new service, port or command).
- **`docs/external-apis.md`** — no change; nothing external is called here.

---

## Review

**This spec needs your approval before implementation begins.**

Ten decisions are proposed (**D-32**–**D-41**), and three of them answer the open questions the
prompt raised:

1. **D-32** *(the prompt's sections-vs-tabs question)* — **one scroll with Upcoming/Past sections**,
   matching the canvas, implemented as two independent paginated queries so the layout can change
   later without a data-layer rewrite. This is the one worth arguing with if you disagree.
2. **D-33** *(the prompt's optimistic-create/dedup risk)* — the optimistic entry is **replaced** by
   the server response, never merged with it, with the divergence case as a required test.
3. **D-34** *(the prompt's date-picker risk)* — exactly **one** platform fork in the whole branch,
   inside `DateField`, with a single shared prop contract.

The rest set client-side conventions that later frontend prompts will copy: `Intl`-based formatting
converted in one place (**D-38**), advisory-not-authoritative client validation (**D-36**), a width
breakpoint rather than a platform branch for phone/desktop (**D-39**), cache-backed offline reads
with **no** write queue (**D-37**), permanent delete with honest copy (**D-40**), and Hydra-cursor
infinite scroll at page size 20 (**D-41**).

**One thing to confirm:** D-37 rules out an offline write queue. If "add a concert while I'm at the
venue with no signal" is a use case you consider essential to the MVP, say so now — it is a
materially larger feature and belongs in its own spec rather than being smuggled into this branch.
