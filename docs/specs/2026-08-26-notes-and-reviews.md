# FEATURE — Notes and reviews (the concert page becomes a gig diary)

| | |
|---|---|
| **Spec ID** | `2026-08-26-notes-and-reviews` |
| **Backlog prompt** | `docs/prompts/20-notes-and-reviews.md` |
| **Command** | `/feature notes-and-reviews` |
| **Primary agent** | `backend-engineer` + `frontend-engineer` (one branch, one PR — the entity, the migration and the concert-page section ship together) |
| **Type** | **FEATURE — implementation follows this document directly.** One branch `feature/notes-and-reviews`, one PR (`CLAUDE.md` — *one feature, one spec, one branch*) |
| **Depends on** | `05` concert domain (merged — `Concert.note`, `ConcertOwnerExtension`, `ConcertLocator`, D-27, D-30, D-31) · `07` concert tracker UI (merged — `ConcertCard`, `ReservedSection`, `DisclosureSection`, D-32, D-36, D-37) · `19` concert page playback (merged — `PlaylistSection` occupies the region directly above this one) · `09` setlist.fm integration (merged — `Song`, `Setlist`, needed only by the structured highlight) · `02` design system · `06` design canvas (`docs/design/canvas/screens/ConcertDetail.dc.html`) |
| **Followed by** | `21` social sharing — `ShareLink` decides, per link, whether a review is included. **This spec deliberately ships no visibility flag for it to reuse** (D-238) |
| **Implemented by** | *(this is the implementation)* |
| **Decisions** | **D-227** – **D-247** |
| **Status** | **Draft — review requested** |

---

## Overview

### What this feature is

Prompt 05 shipped a single nullable `text` column, `Concert.note`, with an explicit disclaimer
attached to it (D-30): *"Prompt 20 owns notes as a feature (formatting, per-band notes, ratings,
visibility). Here it is one plain column."* This is prompt 20. The column is promoted into
`ConcertReview` — an owned entity with a rating, a highlight, and its own timestamps — and the
column's contents move into it rather than being abandoned.

The product argument is in the prompt and it is worth repeating because it shapes every decision
below: **the playlist is the artefact, the note is the memory.** Setlistify without this feature is
a playlist generator with a date field. With it, the concert page is a page worth returning to
years later, and the concert list is a diary rather than a queue.

The design constraint follows from *when* the writing happens: on a phone, late, tired, on the way
home. Every decision here trades comprehensiveness for one-thumb speed. There is one screen, one
rating control with five targets, one text box, and one optional highlight — and the whole thing is
optional in both directions: rating without notes and notes without rating are both valid reviews
(D-231).

### What the code looks like today

Three facts, verified against the current tree, that the design leans on:

| Fact | Where | Consequence |
|---|---|---|
| `Concert.note` exists, is `text`, nullable, capped at 2000 chars | `backend/src/Entity/Concert.php:79`, `ConcertInput.php:54`, `ConcertPatchInput.php:48`, `ConcertOutput.php` | There is real data to migrate, and three DTOs to change in the same commit |
| **The note is written but never read back on the concert page.** `ConcertForm` has a note field; the detail screen renders `<ReservedSection testID="reserved-note" title="Your note" comingIn="prompt 20" />` in its place | `frontend/components/concert/ConcertForm.tsx:315`, `frontend/app/(app)/concerts/[id]/index.tsx:191` | Anything a user typed into that field today is **invisible to them**. The migration is not just data preservation — it is the first time this content is displayed at all |
| The canvas already committed to the shape | `docs/design/canvas/screens/ConcertDetail.dc.html:150` — *"Star rating and a longer write-up will live here"* | The rating-scale question (D-230) is not open in the way the prompt implies; prompt 06 answered it visually and this spec ratifies it rather than re-opening it |

The second row is the one to keep in mind while reading the migration section. A user who typed
"bought tickets the day they went on sale" into the add-concert form has never seen that sentence
again. Dropping it in a migration would be losing data the user does not know they have.

### Load-bearing rules this spec does not reverse

| Rule (`CLAUDE.md` / prior spec) | How this design honours it |
|---|---|
| **404, not 403, for another user's data** (D-27, `docs/architecture.md` §11) | `ConcertReviewOwnerExtension` is a byte-for-byte copy of `ConcertOwnerExtension`'s shape, and the parent concert is resolved through `ConcertLocator` first — so an unowned `concertId` 404s before the review table is touched at all (D-229). Two gates, both failing closed |
| **The backoffice never weakens the public gate** (D-47) | The admin reads reviews through Doctrine directly. `ConcertReviewOwnerExtension` gains no role branch. And the admin sees *that* a review exists, never its text (D-243) |
| **The OpenAPI spec is the single source of truth** | `ConcertReviewResource` is an API Platform resource; `frontend/api/` is regenerated before any client code that calls it is written. No endpoint is listed in a README |
| **Data persistence and sensitive logic live in the backend** | The one exception is the post-concert prompt's *dismissal* state, which is deliberately client-local and is argued for explicitly (D-242) |
| **`note` is plain text with no rendering contract** (D-30) | Extended, not relaxed: `notes` is plain text, rendered in `<Text>`, never as HTML or Markdown, with a static test (D-237) |
| **Offline: cached reads yes, no write queue** (D-37) | Unchanged. A failed review write surfaces an error and *keeps the draft in the editor* rather than queueing it (D-246) |
| **setlist.fm data is cached, never re-fetched casually** | The structured highlight reads `Song` rows that are already in our database. It never triggers a `SetlistGateway` call, so it spends none of the 1,440/day budget (D-232) |

### The four questions the prompt left open, and what this spec does with them

The prompt named three open questions and one compliance obligation. All four are **closed here**,
per the task's instruction that this document decide rather than defer.

1. **Rating scale** → **1–5 stars, integers only, nullable.** D-230.
2. **Structured vs. free-text highlight** → **both columns, always.** A nullable FK to `Song` *plus*
   an always-populated text snapshot. D-232.
3. **Availability on upcoming concerts** → **blocked server-side, de-emphasized client-side**, with
   an explicit exemption for rows the migration creates. D-234, D-235.
4. **GDPR erasure and backup coverage** → cascade at the database level, an assertion added to the
   existing `UserEraser` test, and the review body excluded from the backoffice, from audit values
   and from logs. D-243, D-244.

---

## Goals

| Goal | Success looks like |
|---|---|
| The memory has a home | `ConcertReview` mapped, migrated, and reachable at one obvious place on the concert page |
| Nothing typed is lost | Every non-blank `Concert.note` in the database becomes a `ConcertReview`, provably, including emoji and multi-byte text |
| One review, one concert, no ambiguity | A second write edits the first — by the shape of the endpoint, not by a caught constraint violation |
| Private means structurally private | No column, endpoint, backoffice screen, log line or serialization path can expose one user's review to another. Proven by test, not by convention |
| The diary is browsable | The concert list shows which shows have been written up, without an N+1 |
| The nudge is a nudge | The post-concert prompt appears once, in one place, and goes away for good when dismissed |
| Text survives the round trip | 🎸, 家族, and a ZWJ-sequence emoji come back byte-identical on web, iOS and Android, and count sensibly against the length limit |
| The highlight degrades | A concert with no setlist still gets a highlight; a setlist re-fetched overnight does not blank one already written |

---

## User Stories

### US-1 — Write it down before I forget

> As a **user who just got home from a gig**, I want to open the concert and write what it was like
> in a few taps, so that the memory is captured while it is still fresh.

**Acceptance criteria**

- **AC-1.1** The concert detail screen for a **past** concert renders a `ReviewSection` in the slot
  currently occupied by `<ReservedSection testID="reserved-note" title="Your note" />`, directly
  below `PlaylistSection` and above the share region reserved for prompt 21 (`ConcertDetail.dc.html`).
- **AC-1.2** With no review yet, the section shows a single primary affordance ("Write about this
  show") and nothing else — no empty star row, no empty text box. The unwritten state is an
  invitation, not a form.
- **AC-1.3** Activating it opens the editor: a 5-star rating control, a multi-line notes field, and
  a collapsed "Best song of the night" disclosure (`DisclosureSection`, prompt 07). On phone widths
  the editor is a sheet; on desktop widths it expands in place. This is a width breakpoint, not a
  `Platform.OS` branch (D-39, D-245).
- **AC-1.4** The notes field is focused on open, on all three platforms, so the fast path from
  "open concert" to "typing" is two taps.
- **AC-1.5** Saving issues `PUT /api/concerts/{concertId}/review` and returns **201** on first write,
  **200** on a subsequent one (D-228). The section re-renders with the saved review.
- **AC-1.6** A review with a rating and no notes saves. A review with notes and no rating saves. A
  review with neither is rejected **422** with `propertyPath: ""` and a message naming both fields
  (D-231); the client disables the save affordance in that state rather than letting the round trip
  happen (D-36 — advisory client mirror, authoritative server).
- **AC-1.7** Round trip is verified for `🎸`, `👨‍👩‍👧‍👦` (a ZWJ sequence), `家族`, `Sigur Rós` and a
  string mixing all of them: what is read back equals what was sent, byte for byte, on web, iOS and
  Android.

### US-2 — Change my mind, or take it back

> As a **user**, I want to edit or delete what I wrote, so that a review is a living note and not a
> permanent record I regret.

**Acceptance criteria**

- **AC-2.1** With a review present, the section renders the rating, the notes and the highlight, plus
  edit and delete affordances.
- **AC-2.2** Editing re-opens the same editor pre-filled. Saving issues the same `PUT` and returns
  **200**.
- **AC-2.3** `DELETE /api/concerts/{concertId}/review` returns **204** and the section returns to its
  AC-1.2 unwritten state. Delete is confirmed first, reusing `DeleteConfirmation` (prompt 07), and
  the copy says it is permanent — because it is (D-40's precedent; no soft delete, no undo).
- **AC-2.4** `DELETE` on a concert with no review returns **404**, not 204 — consistent with the
  singleton read.
- **AC-2.5** Editing and deleting are **never** blocked by the past-only rule, even if the concert's
  date has since been moved into the future (D-235). The rule gates first writes, not custody of
  what is already written.
- **AC-2.6** `updatedAt` changes on every successful `PUT`; `createdAt` does not.

### US-3 — Only one review, and no way to trip over that

> As a **user**, I want the app to hold exactly one review per concert, so that there is never a
> question of which one is mine.

**Acceptance criteria**

- **AC-3.1** `GET /api/concerts/{concertId}/review` returns the single review (**200**) or **404**.
  There is no collection under a concert, and no review id in any URL (D-228).
- **AC-3.2** A second `PUT` **updates** the existing row. It does not create, does not 409, and does
  not require the client to know whether a review exists — which is exactly what makes the "attempting
  a second edits the first" acceptance criterion true by construction rather than by error handling.
- **AC-3.3** A `UNIQUE (owner_id, concert_id)` index exists on `concert_reviews`. A test writes two
  rows for the same pair directly through the entity manager and asserts the constraint fires — the
  database is the backstop even though the endpoint shape makes it unreachable through the API.
- **AC-3.4** A concurrency test issues two simultaneous first-time `PUT`s for the same concert: one
  wins, the other is retried once on unique violation and lands as an update. Neither returns a 500.
- **AC-3.5** `ConcertReview.owner` always equals `ConcertReview.concert.owner`. This is asserted on
  write and covered by a test; the unique index is on the pair rather than on `concert_id` alone so
  that the invariant is expressed rather than assumed (D-227).

### US-4 — My reviews are mine, structurally

> As a **user**, I want it to be impossible for anyone else to read what I wrote, so that I write
> honestly.

**Acceptance criteria**

- **AC-4.1** For every operation (`GET`, `PUT`, `DELETE`), a request from user B for a concert owned
  by user A returns **404** — byte-identical to the response for a `concertId` that does not exist.
  A test matrix covers all three verbs × {other user's concert, nonexistent id} = six cases.
- **AC-4.2** The parent concert is resolved through `ConcertLocator` (which already applies
  `ConcertOwnerExtension`) *before* `concert_reviews` is queried, so the review table is never read
  on behalf of a non-owner at all (D-229).
- **AC-4.3** `ConcertReviewOwnerExtension` applies `WHERE owner = :current_user` to every review
  query, as the second gate, matching `ConcertOwnerExtension` exactly. Neither class gains a
  `ROLE_ADMIN` branch (D-47).
- **AC-4.4** There is **no** endpoint, anywhere in the OpenAPI document, that returns a review
  belonging to a user other than the authenticated one. A static test greps the generated spec for
  the review schema and asserts every operation carrying it is under an owner-gated path.
- **AC-4.5** There is **no** `isPublic`, `visibility` or `sharedAt` column on `ConcertReview`
  (D-238). A schema test asserts the column list, so prompt 21 has to make sharing an explicit
  decision rather than finding a flag already flipped.
- **AC-4.6** Review notes never appear in application logs, in `AuditLogEntry.values`, or in any
  exception message (D-243). A test asserts the audit payload for an admin action touching a review
  contains no `notes` key.

### US-5 — The best song of the night

> As a **user**, I want to name the song that made the night, so that the review says something
> specific rather than generic.

**Acceptance criteria**

- **AC-5.1** When the concert has at least one `Setlist` in our database for a band in its lineup,
  the highlight control is a **picker over those songs**, grouped by band, in setlist order, with a
  free-text escape hatch at the bottom ("Something else…").
- **AC-5.2** When there is no such setlist, the control is a plain text field. The user is not told
  about the picker they are not getting; there is no "no setlist available" apology in this section
  (`PlaylistSection` already explains the missing-setlist case directly above it).
- **AC-5.3** Choosing from the picker sets **both** `highlightSongId` and `highlightTitle` (the
  snapshot). Typing free text sets `highlightTitle` only, with `highlightSongId` null (D-232).
- **AC-5.4** Rendering a highlight **always** reads `highlightTitle`. The FK is never dereferenced for
  display. Consequence: a `Song` row replaced or removed by the nightly setlist refresh nulls the FK
  (`ON DELETE SET NULL`) and the user's highlight still reads correctly the next morning.
- **AC-5.5** A `highlightSongId` that does not belong to a setlist of a band in **this** concert's
  lineup is rejected **422** (D-233) — the field is not a general-purpose song reference and must not
  become an id-probing oracle.
- **AC-5.6** Building the picker performs **zero** `SetlistGateway` calls. It reads `Setlist`/`Song`
  rows already persisted. A test asserts the gateway is not invoked during a review write or read.
- **AC-5.7** The highlight is optional. Clearing it sets both columns to null.

### US-6 — The diary is browsable

> As a **user with fifty concerts**, I want to see at a glance which ones I wrote up, so that the list
> is a diary rather than a backlog.

**Acceptance criteria**

- **AC-6.1** `ConcertOutput` gains `reviewSummary`: `{ rating: int|null, highlightTitle: string|null,
  updatedAt: string }` or `null` when there is no review (D-241). Consistent with AC-2.7 of spec 05,
  the key is always present; its value is `null` when absent, never omitted.
- **AC-6.2** `reviewSummary` **never contains the notes body.** The list does not need it, and
  shipping it in every list page would put personal writing into more caches than necessary.
- **AC-6.3** `ConcertCard` renders the summary as a compact indicator: the star rating when present,
  otherwise a neutral "Written up" `Badge`. Unreviewed **past** concerts render nothing — absence is
  the signal; a "not reviewed" badge on every row would be noise on the common case.
- **AC-6.4** Upcoming concerts never render a review indicator, reviewed or not.
- **AC-6.5** The collection provider produces `reviewSummary` with a single `LEFT JOIN` over the page
  of concerts. A test asserts the query count for a 20-item page is unchanged from before this
  feature — no N+1 (D-241).
- **AC-6.6** `GET /api/concerts?reviewed=true|false` filters the list. Index-backed, restricted to the
  owner's rows like every other concert filter.

### US-7 — A nudge, not a nag

> As a **user whose gig was last night**, I want a gentle reminder that I can write it up, so that I
> remember to — without the app pestering me.

**Acceptance criteria**

- **AC-7.1** The concert list shows **at most one** review prompt card, at the head of the **Past**
  section (D-32's single-scroll layout).
- **AC-7.2** It appears for the most recently past concert that (a) became past within the last **30
  days**, (b) has no review, and (c) has not been dismissed.
- **AC-7.3** It is dismissible with one tap, and dismissal is permanent for that concert on that
  device (D-242). Dismissing reveals the next eligible concert's card, if any — but not immediately;
  the next card appears on the next list mount, so dismissing three times in a row is not possible.
- **AC-7.4** Writing a review removes the card for that concert without a dismissal.
- **AC-7.5** There are **no push notifications, no emails and no badge counts.** The prompt exists in
  exactly one place, and that place is a screen the user chose to open.
- **AC-7.6** The card is never shown for an upcoming concert, and never for a concert older than the
  window — a show from three years ago does not get retro-nagged when this feature ships.

### US-8 — Nothing I typed is lost

> As a **user who used the note field on the add-concert form**, I want what I wrote to still be
> there, so that upgrading the app does not quietly delete my words.

**Acceptance criteria**

- **AC-8.1** The migration creates one `ConcertReview` for every `Concert` whose `note` is non-null
  and non-blank after trimming, with `owner = concert.owner`, `notes = concert.note` verbatim,
  `rating = NULL`, `highlightSongId`/`highlightTitle = NULL`, `createdAt`/`updatedAt =
  concert.updated_at`, and `sourceNoteMigratedAt = now()` (D-240).
- **AC-8.2** `notes` is copied **byte for byte**. No trimming, no normalization, no re-encoding. The
  trim in AC-8.1 decides *whether* to migrate; it does not alter *what* is migrated.
- **AC-8.3** A `Concert` with `note = NULL`, `''` or `'   '` produces no review.
- **AC-8.4** Notes are migrated **regardless of the concert's upcoming/past status**. The past-only
  rule (D-234) governs API writes, not existing data (D-235) — a note on an upcoming show ("get there
  early") is exactly the kind of content this criterion exists to protect.
- **AC-8.5** The migration copies, verifies the row counts match, and **then** drops
  `concerts.note`, all in one transaction. `down()` re-adds the column and copies the migrated rows
  back before deleting them (D-239).
- **AC-8.6** A functional migration test seeds concerts with: a plain note, a note containing a ZWJ
  emoji sequence, a 2000-character note at the old limit, a whitespace-only note, and a null note —
  runs the migration, and asserts exactly the expected reviews exist with exactly the expected text.
- **AC-8.7** `Concert.note` is removed from `ConcertInput`, `ConcertPatchInput`, `ConcertOutput`,
  `ConcertOutputMapper`, the entity, and `ConcertForm`. There is no second place to write a note
  after this branch.
- **AC-8.8** Running the migration twice is a no-op on the second run (guarded by
  `sourceNoteMigratedAt` provenance plus the unique index, D-240).

### US-9 — Text behaves

> As a **user who writes in Spanish, Japanese and emoji**, I want my text stored and displayed
> correctly, so that the app does not mangle what I wrote.

**Acceptance criteria**

- **AC-9.1** `notes` is capped at **4000** grapheme clusters; `highlightTitle` at **200**. Counted
  with `Assert\Length(countUnits: Length::COUNT_GRAPHEMES)`, so `👨‍👩‍👧‍👦` costs 1, not 7 (D-236).
- **AC-9.2** The client mirrors the count with `Intl.Segmenter` where available and `[...str].length`
  as fallback, advisory only; the server is authoritative (D-36).
- **AC-9.3** Exceeding a limit returns **422** RFC 7807 with the violation on the right
  `propertyPath`, rendered against the right field by the existing `violations.ts` mapping (D-36).
- **AC-9.4** Review text is rendered exclusively through React Native `<Text>`. A static test asserts
  the review module contains no `dangerouslySetInnerHTML`, no `RenderHtml`, and no `WebView`
  (D-237) — the same shape of guard as spec 19's D-224 provider-literal test.
- **AC-9.5** A note whose content is `<script>alert(1)</script>`, `{{7*7}}` or
  `'); DROP TABLE concerts;--` is stored verbatim and displayed verbatim as visible characters, on all
  three platforms. It is neither executed nor escaped-and-shown-mangled.
- **AC-9.6** Postgres columns are `text` / `varchar` on a UTF-8 database; no `COLLATE` or encoding
  override is introduced.

### US-10 — Erasure actually erases

> As **the operator answering a GDPR request**, I want a user's reviews gone when the user is gone,
> so that erasure is complete.

**Acceptance criteria**

- **AC-10.1** `concert_reviews.owner_id` and `concert_reviews.concert_id` are both
  `ON DELETE CASCADE` (D-244). Deleting a concert deletes its review; erasing a user deletes both.
- **AC-10.2** The existing `UserEraser` test gains an assertion that a user's reviews are gone after
  erasure — and that another user's reviews, on their own concerts, survive.
- **AC-10.3** `UserEraser` itself needs no code change. If it did, that would mean the cascade was
  wrong; the test is what proves it.
- **AC-10.4** The backoffice `Concert` view shows whether a review exists, its rating and its
  timestamps. **It does not show the notes body**, not even truncated (D-243).
- **AC-10.5** R-4 of spec 08 ("re-check the cascade list every time a new user-owned entity is
  added") is discharged in this branch, and `docs/architecture.md` §11's erasure paragraph names
  `ConcertReview`.

### US-11 — The contract holds and the tests are green

> As **the next engineer**, I want the client types generated and the behaviour covered, so that I do
> not discover this feature's edges by breaking them.

**Acceptance criteria**

- **AC-11.1** `frontend/api/` is regenerated from the spec **before** any client code calling the
  review endpoints is written. No hand-written review request or response type exists.
- **AC-11.2** Backend tests: CRUD happy paths, the six-case ownership matrix (AC-4.1), the unique
  constraint (AC-3.3), the concurrency retry (AC-3.4), the empty-review rejection (AC-1.6), the
  highlight-scope rejection (AC-5.5), grapheme limits (AC-9.1), and the migration (AC-8.6).
- **AC-11.3** Frontend tests: unwritten / written / editing / error states of `ReviewSection`, the
  list indicator, the prompt card's appear-dismiss-stay-dismissed cycle, and emoji round trip.
- **AC-11.4** Static tests: no HTML rendering in the review module (AC-9.4); no `visibility` column
  (AC-4.5); no review schema on a non-owner-gated path (AC-4.4).
- **AC-11.5** Green in CI, with no live external API calls (D-2's no-live-APIs-in-CI rule).

---

## Technical Approach

### 1. One entity, one table — D-227

```
User ─┬─< Concert ─┬─< ConcertBand >─ Band
      │      │
      │      └──< Playlist
      │
      └─< ConcertReview ── Concert          (UNIQUE(owner_id, concert_id))
                    │
                    └─ ? Song               (highlight_song_id, ON DELETE SET NULL)
```

`backend/src/Entity/ConcertReview.php`, table `concert_reviews`:

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `integer` identity | no | |
| `owner_id` | FK `users` | no | `ON DELETE CASCADE` |
| `concert_id` | FK `concerts` | no | `ON DELETE CASCADE` |
| `rating` | `smallint` | yes | 1–5 inclusive, DB `CHECK` plus `Assert\Range` (D-230) |
| `notes` | `text` | yes | plain text, ≤ 4000 graphemes (D-236) |
| `highlight_song_id` | FK `songs` | yes | `ON DELETE SET NULL` (D-232) |
| `highlight_title` | `varchar(200)` | yes | the snapshot; the only thing ever displayed (D-232) |
| `source_note_migrated_at` | `timestamptz` | yes | provenance; non-null iff created by the migration (D-240) |
| `created_at` / `updated_at` | `timestamptz` | no | |

Indexes: `UNIQUE (owner_id, concert_id)`, `INDEX (concert_id)`, `INDEX (highlight_song_id)`.

Why an entity rather than four more columns on `Concert`: the review has its own lifecycle (created
and deleted independently of the concert), its own timestamps that must not be conflated with the
concert's, and — decisively — prompt 21 needs something a `ShareLink` can *include or exclude*.
Excluding a set of columns from a serialization is a rule someone has to remember; excluding a
related row is a join someone has to add. The second fails safe.

The unique index is on `(owner_id, concert_id)` rather than `concert_id` alone even though a concert
has exactly one owner today. The pair states the invariant the feature actually depends on, and
`ConcertReviewWriter` asserts `review.owner === concert.owner` on every write (AC-3.5) so the two
never drift.

### 2. The endpoint is a singleton, and that is what enforces "one review" — D-228

`backend/src/ApiResource/ConcertReviewResource.php`:

| Operation | Path | Success | Absent |
|---|---|---|---|
| `Get` | `GET /api/concerts/{concertId}/review` | 200 | 404 |
| `Put` (`allowCreate: true`) | `PUT /api/concerts/{concertId}/review` | 201 created / 200 updated | — |
| `Delete` | `DELETE /api/concerts/{concertId}/review` | 204 | 404 |

There is **no review id in any URL** and no `POST`. The acceptance criterion "attempting a second
edits the first" is then not a behaviour to implement — it is the only thing the endpoint can do.
The alternative (`POST /api/reviews` returning 409 with a pointer to the existing id) was rejected:
it makes the client responsible for knowing whether a review exists before it writes, which is
precisely the state a tired user on a train should not cause a bug in.

`ConcertReviewInput` carries `rating`, `notes`, `highlightSongId`, `highlightTitle`. It carries no
`concertId` (it is in the path), no `owner` and no `id` — the D-29 DTO discipline from spec 05.

**No `GET /api/reviews` collection** ships in this branch. The diary is browsed through the concert
list, which now carries `reviewSummary` (D-241); a second listing surface with its own pagination,
filters and ordering would be a feature nobody has asked for. If a "all my reviews" screen is wanted
later it is a small additive spec.

### 3. Ownership: the gate is passed twice, and the first pass is the real one — D-229

```
PUT /api/concerts/42/review
  │
  ├─ 1. ConcertLocator::locate(42)          ← applies ConcertOwnerExtension (D-27)
  │      not the owner? → null → 404, identical to a nonexistent id
  │
  ├─ 2. ConcertReviewOwnerExtension          ← WHERE owner = :current_user
  │      the second gate; unreachable in practice, which is the point
  │
  └─ 3. write
```

`backend/src/Security/ConcertReviewOwnerExtension.php` is a structural copy of
`ConcertOwnerExtension` — the same `QueryCollectionExtensionInterface` +
`QueryItemExtensionInterface` pair, the same `1 = 0` defensive dead end for an unauthenticated
principal, the same absence of any role branch. The existing class's own docblock anticipates this:
*"the pattern is drop-in reusable by a later resource … (playlists, notes)."* This is that later
resource, and copying the shape verbatim is deliberate — a shared abstract base class would make the
gate one edit away from being weakened for every resource at once.

### 4. Rating: five stars, integers, and no way back — D-230

| Candidate | Verdict |
|---|---|
| 1–10 | Too fine for the question being asked. Nobody can distinguish a 6 from a 7 gig, and a scale people cannot use produces data nobody trusts |
| good / great / unforgettable | Charming, and unsortable, unaverageable, and untranslatable into any future "your year in gigs" summary |
| Half-stars | Doubles the precision and quadruples the touch-target problem on a phone at midnight |
| **1–5 integer stars** | Universally understood, five targets fit a thumb, sortable, and already drawn |

Stored as `smallint` with a `CHECK (rating BETWEEN 1 AND 5)` and `Assert\Range`. **Nullable** — a
review can be notes-only (D-231), and every migrated note is exactly that (D-240).

Deciding this now matters because the prompt is right that the scale cannot be changed later: a
column of 1–5 values re-interpreted as 1–10 is silently wrong for every row already written. The
canvas (`ConcertDetail.dc.html:151`, *"Star rating and a longer write-up"*) already committed to
stars; this decision ratifies that rather than re-litigating it.

The API returns the integer. It does not return a rendered star string, a percentage, or a label —
presentation belongs to the client.

### 5. A review must say something — D-231

`rating IS NULL AND (notes IS NULL OR trim(notes) = '')` is rejected **422** with an empty
`propertyPath` (the violation is on the object, not on either field). Otherwise `PUT` becomes a way
to create an empty row that renders as a review the user did not write, and `DELETE` loses its
meaning. The client mirrors this by disabling save, so the round trip normally never happens.

The highlight alone does **not** satisfy the rule: a review that is only a song title, with no rating
and no words, is a data artefact rather than a memory.

### 6. The highlight is structured *and* free text, always — D-232, D-233

This is the prompt's hardest open question, and the answer is that the two options are not
alternatives.

```php
#[ORM\ManyToOne(targetEntity: Song::class)]
#[ORM\JoinColumn(name: 'highlight_song_id', nullable: true, onDelete: 'SET NULL')]
private ?Song $highlightSong = null;

/** The snapshot. The ONLY value ever rendered (AC-5.4). */
#[ORM\Column(name: 'highlight_title', type: 'string', length: 200, nullable: true)]
private ?string $highlightTitle = null;
```

| Case | `highlightSongId` | `highlightTitle` |
|---|---|---|
| Picked from a setlist | the `Song` id | `song.title` at pick time |
| Typed freely (no setlist, or "Something else…") | `null` | what the user typed |
| Song row later replaced by the nightly refresh | `null` (SET NULL) | **unchanged** |
| No highlight | `null` | `null` |

The structured id is what makes future aggregation possible — "the song you have seen live most
often" needs joinable identity, not a string that differs by capitalisation across five gigs. The
snapshot is what makes the feature *survive*: `Song` rows are cached setlist.fm data (spec 09), and
the nightly refresh can replace them. A FK-only design would blank a user's highlight overnight
through no action of theirs. That is unacceptable for personal writing, and it is the reason both
columns exist rather than one.

`PlaylistTrack` already uses exactly this pattern — `sourceSong` nullable `SET NULL` alongside a
`sourceTitle` string snapshot (`backend/src/Entity/PlaylistTrack.php:42,59`). This is the
established shape in the codebase, not a new invention.

**D-233 — the FK is validated against this concert's lineup.** A supplied `highlightSongId` must
resolve to a `Song` whose `Setlist.band` is one of the concert's `ConcertBand` bands; otherwise
**422**. Without that check the field accepts any song id in the database, which turns a private
review into a probe for whether an arbitrary setlist row exists. The check is one join and it closes
the hole before it exists.

### 7. Reviewing is for shows that happened — D-234, D-235

**D-234.** A first write (`PUT` creating a row) on a concert whose derived `status` is `upcoming` is
rejected **422**, `propertyPath: ""`, code `REVIEW_NOT_YET`, message *"You can write about this
concert once it has happened."* Status is D-24's existing `pastAfter <= now()` comparison — no new
time logic, no new column, no viewer-timezone dependency.

Blocked rather than merely de-emphasized, because the two rules disagree about what the feature *is*.
A review is a record of an experience; a review of a show that has not happened is a plan, and
Setlistify has a place for plans (the concert itself). Allowing it and hiding the button means the
API says one thing and the UI another — and prompt 21 would then have to decide what it means to
share a review of a future gig.

Client side, an upcoming concert renders the region as a de-emphasized "unlocks after the show"
panel, with no compose affordance — the same treatment the canvas already specifies for the upcoming
playlist region (`ConcertDetail.dc.html:208`: *"same slot, different copy and affordance depending on
why it's empty"*). So the answer to the prompt's question is **both**: blocked on the server,
de-emphasized in the client, and the client's de-emphasis is an explanation rather than an
enforcement.

**D-235 — three explicit exemptions**, each of which exists because the alternative destroys or traps
user data:

1. **The migration is exempt** (AC-8.4). A note on an upcoming show is migrated like any other. The
   rule governs API writes; it is not a filter on history.
2. **Editing is never blocked.** If a review exists and the concert's date is later moved into the
   future, `PUT` still updates it. The check is on *creation*, not on custody.
3. **Deleting is never blocked**, for the same reason, plus the stronger one: a user must always be
   able to remove their own words.

### 8. The migration: copy, verify, drop, in one transaction — D-239, D-240

`backend/migrations/VersionYYYYMMDDHHMMSS.php`:

```sql
-- 1. create concert_reviews (+ indexes, + FKs, + CHECK)
-- 2. INSERT INTO concert_reviews (owner_id, concert_id, notes, created_at, updated_at,
--                                 source_note_migrated_at)
--    SELECT c.owner_id, c.id, c.note, c.updated_at, c.updated_at, now()
--    FROM concerts c
--    WHERE c.note IS NOT NULL AND btrim(c.note) <> '';
-- 3. verify: the inserted count equals the count of that same WHERE clause; abort otherwise
-- 4. ALTER TABLE concerts DROP COLUMN note;
```

Step 3 is not ceremony. It is the difference between "the migration ran" and "the data arrived", and
it runs inside the same transaction as step 4, so a mismatch rolls the drop back with it.

`down()` re-adds `concerts.note`, copies `notes` back for rows with `source_note_migrated_at IS NOT
NULL`, then drops the table. A down migration that silently loses text is worse than no down
migration; this one is honest about restoring exactly what it took.

**Why drop the column rather than deprecate it.** Two writable homes for the same sentence is a
divergence waiting to happen: the add-concert form writes one, the review editor writes the other,
and within a week nobody knows which the concert page should show. The column goes in the same
commit that stops writing it, and `ConcertInput`, `ConcertPatchInput`, `ConcertOutput`,
`ConcertOutputMapper`, `Concert` and `ConcertForm` all lose the field together (AC-8.7). This is a
breaking API change, which is exactly why the generated client is regenerated in the same branch: any
remaining client reference becomes a compile error rather than a runtime `undefined`.

**D-240 — `sourceNoteMigratedAt` earns its column.** It makes the migration verifiable after the
fact (`SELECT count(*) WHERE source_note_migrated_at IS NOT NULL`), makes `down()` precise about
which rows it created, and makes a re-run a no-op. It is never exposed through the API — it is
provenance, not content.

Note the shape of what is migrated: `rating = NULL`, `notes = <the note>`. A migrated review is a
notes-only review, which is exactly the case D-231 was written to permit and D-230 made the rating
nullable to allow. The three decisions were designed together.

### 9. The list indicator, without an N+1 — D-241

`ConcertOutput` gains `?ConcertReviewSummaryOutput $reviewSummary` — `{ rating, highlightTitle,
updatedAt }`, or `null`.

`App\State\Provider\ConcertCollectionProvider` adds a `LEFT JOIN App\Entity\ConcertReview r WITH
r.concert = c AND r.owner = :current_user` and selects the three summary fields alongside the
concert. One query for the page, exactly as before (AC-6.5). The item provider does the same for a
single concert.

**The notes body is not in the summary** (AC-6.2). The list does not render it, and personal writing
should live in as few response caches as possible. The full body comes from the review endpoint,
fetched by the concert page that is actually going to display it.

`?reviewed=true|false` is a filter on `r.id IS [NOT] NULL` over the same join, index-backed by
`UNIQUE (owner_id, concert_id)`.

### 10. The nudge is client-local, deliberately — D-242

Dismissal state lives in `AsyncStorage` under `review-prompt-dismissed:<concertId>`, not in the
database.

This is the one place this spec puts state in the client, so it needs the argument. A server-side
dismissal is a table, an endpoint, an owner gate, a cascade entry in the erasure path, and a
migration — all to remember that someone once tapped an ✕ on a suggestion. The cost of getting it
wrong is that a user who installs the app on a second device is offered the prompt once more, on a
concert they chose not to write about, and dismisses it again. That is not a bug worth a table.

It is also not user *content* — losing it destroys nothing. Every rule about persistence in
`CLAUDE.md` is about data and sensitive logic; this is neither.

Selection logic, in one pure function so it is testable without a screen:
`eligible = pastConcerts.filter(c => !c.reviewSummary && c.pastAfter > now - 30d && !dismissed(c.id))`,
then take the most recent. Evaluated once per list mount (AC-7.3), so the card cannot be dismissed
repeatedly in a single sitting.

### 11. Rendering and escaping — D-237

Review text is plain text with **no rendering contract** — the same commitment D-30 made for
`Concert.note`, carried forward rather than relaxed. No Markdown, no HTML, no autolinking, no
mentions.

React Native's `<Text>` renders its children as text on every platform; there is no interpolation
path to escape. The risk is therefore not today's code but tomorrow's — someone adding Markdown
support "just for bold" and reaching for an HTML renderer. `AC-9.4`'s static test is aimed squarely
at that future commit: no `dangerouslySetInnerHTML`, no `RenderHtml`, no `WebView` in the review
module. Same guard shape as spec 19's D-224.

Backend side there is nothing to escape either: API Platform serializes to JSON, Doctrine
parameterizes every query, and the text is never concatenated into SQL, HTML or a shell command.
AC-9.5's payloads are stored and returned as ordinary characters.

### 12. Length limits in graphemes — D-236

`notes` ≤ **4000**, `highlightTitle` ≤ **200**, both with
`#[Assert\Length(max: …, countUnits: Length::COUNT_GRAPHEMES)]`.

Graphemes, not bytes and not code points, because the alternatives are both wrong in a way the user
can see. `👨‍👩‍👧‍👦` is 25 bytes and 7 code points, and it is *one character* on the screen. A code-point
limit would tell someone their four-emoji sign-off consumed 28 of their characters, which reads as a
bug.

4000 rather than the old 2000, because the old field was a one-line afterthought on a form and this
is the place someone writes about their night. 4000 graphemes is roughly 700 words — long enough that
nobody hits it accidentally, short enough that the column stays sane and nobody pastes a book.

### 13. The backoffice sees the fact, not the words — D-243

The admin needs reviews visible enough to answer an abuse report and to honour an erasure request.
It does not need to read them.

`ConcertReviewCrudController` (EasyAdmin, read-only, no `new`/`edit`) shows: owner, concert, rating,
whether notes are present, the grapheme length, `createdAt`/`updatedAt`. **Not the body.** This is
the same instinct as spec 08's digest-only audit values: the operator gets what the job requires and
no more, so a compromised admin session is not a compromised diary.

Consequently the review body never appears in `AuditLogEntry.values` either (AC-4.6), and the review
module logs ids, never content.

### 14. Frontend shape — D-245, D-246

```
frontend/components/review/
  ReviewSection.tsx      the concert-page region: unwritten / written / upcoming states
  ReviewEditor.tsx       rating + notes + highlight; sheet on phone, inline on desktop
  StarRating.tsx         5 targets, accessible ("Rate 4 out of 5 stars"), read + write modes
  HighlightPicker.tsx    setlist-backed picker, or plain field when there is no setlist
  ReviewPromptCard.tsx   the post-concert nudge
  index.ts
frontend/hooks/useConcertReview.ts   query + PUT/DELETE mutations, cache invalidation
```

**No new platform fork.** Phone vs desktop is the existing width breakpoint (D-39); D-34's rule that
`DateField` is the branch stays true — this feature adds nothing to `Platform.OS`.

Saving invalidates both the review query and the concert list query, so the indicator (AC-6.3) and
the prompt card (AC-7.4) update without a refetch race.

**D-246 — offline is unchanged.** Reads come from the React Query cache (D-37); a write fails with a
recoverable message. The one addition: **the editor keeps the draft text on failure** and does not
close. Losing 400 words to a tunnel is the worst thing this feature could do to someone, and keeping
the sheet open costs nothing. This is not a write queue and does not reopen D-37.

### 15. Test layers — D-247

| Layer | What it proves |
|---|---|
| **Unit** | Grapheme counting at the boundary; the empty-review rule; prompt-card eligibility as a pure function |
| **Functional (backend)** | CRUD; the six-case 404 matrix; unique constraint; concurrent first-write; highlight scope rejection; past-only rule + its three exemptions; `SetlistGateway` never invoked |
| **Migration** | The five seeded shapes of AC-8.6, run against a real Postgres — including the ZWJ emoji and the 2000-char note. Not mocked; the whole point is that the real database round-trips the text |
| **Frontend** | Section states; editor save/fail/draft-retention; list indicator; prompt appear → dismiss → stays dismissed |
| **Static** | No HTML rendering in the review module; no `visibility` column; no review schema on a non-owner-gated path |

The emoji criterion (AC-1.7) is asserted at two levels deliberately: once through the API against a
real database, and once through the client, because a mangling can be introduced by either side
independently.

### Suggested implementation order

1. Entity, repository, migration (copy → verify → drop), migration test. **Green before anything
   else** — this is the step that can lose data.
2. `ConcertReviewResource`, input/output DTOs, state providers/processors, `ConcertReviewOwnerExtension`.
3. Ownership matrix, unique-constraint, past-only and highlight-scope tests.
4. `ConcertOutput.reviewSummary` + the join + the `reviewed` filter + the query-count test.
5. Remove `note` from the three concert DTOs, the entity and `ConcertForm`. Regenerate `frontend/api/`.
6. `ReviewSection` / `ReviewEditor` / `StarRating`, replacing `reserved-note`.
7. `HighlightPicker`, both branches.
8. `ConcertCard` indicator; `ReviewPromptCard`.
9. Backoffice read-only controller; `UserEraser` erasure assertion.
10. Static tests, `/doc-check`, docs.

---

## Out of Scope

| Not here | Where instead |
|---|---|
| Sharing a review publicly | **Prompt 21.** And explicitly: this spec ships no visibility flag for it to inherit (D-238) |
| Photos or video attached to a review | **Prompt 25** |
| Public profiles, following, any social graph | Not on the roadmap |
| Comments or reactions from other users | Not on the roadmap. `ConcertReview` has one author and no thread |
| Per-band or per-song reviews | One review per concert. A festival gets one write-up; the highlight names the moment |
| Markdown, rich text, autolinking, mentions | D-237. Plain text, deliberately |
| A `GET /api/reviews` collection or a dedicated diary screen | The concert list carries the indicator (D-241). A separate listing surface is a later additive spec if wanted |
| Push notifications or email reminders | D-242 / AC-7.5. The nudge lives in one screen the user opened |
| Rating aggregation — a band's average, "your top gigs of 2026" | Real value, and it needs a year of data first. The structured highlight (D-232) is the groundwork |
| Editing a review's `createdAt`, or backdating | Timestamps are ours |
| Server-side dismissal state for the prompt | D-242 |
| Exporting reviews | A GDPR *portability* feature, distinct from erasure. Worth its own prompt |

---

## Dependencies

**Ready now:**

| Dependency | State | Used for |
|---|---|---|
| `Concert`, `ConcertOwnerExtension`, `ConcertLocator`, D-27/D-30/D-31 | merged | The parent, the gate, and the column being promoted |
| `Concert.pastAfter` / derived `status` (D-24) | merged | The past-only rule (D-234), with no new time logic |
| `ConcertCard`, `ReservedSection`, `DisclosureSection`, `DeleteConfirmation`, `TextInput`, `Badge` | merged (prompt 07 / 02) | The whole client surface; only `StarRating` is genuinely new |
| `ConcertDetail.dc.html` reserved region | merged (prompt 06) | The slot, already designed and already labelled "prompt 20" |
| `Setlist`, `Song` (spec 09) | merged | The structured highlight picker, read from our own tables |
| `PlaylistTrack`'s FK+snapshot pattern | merged (spec 14) | Precedent for D-232 |
| `UserEraser`, `AuditLogger` (spec 08) | merged | Erasure cascade + the audit exclusion |
| `violations.ts` RFC 7807 mapping (D-36) | merged | 422 field errors, no new client plumbing |

**Blocked by nothing.** Prompts 07 and 19 are both merged; 19 matters only because `PlaylistSection`
now occupies the region immediately above this one, so the two must sit together without either
being redesigned.

**What this feature blocks:** prompt 21. `ShareLink`'s review inclusion needs a row to include, and
needs it to have no visibility flag of its own (D-238), so that "shared" is a property of a link and
never of a review.

---

## Risks and Open Questions

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| **R-1** | **The migration loses text.** The one irreversible failure in this branch | High | Copy → count-verify → drop, in one transaction (D-239); a real-Postgres migration test with five seeded shapes including a ZWJ emoji (AC-8.6); an honest `down()` |
| **R-2** | **Dropping `concerts.note` breaks a client that has not been regenerated** | Medium | Same branch, same PR, regenerate before editing client code (`CLAUDE.md`). The break is a compile error, which is the intended failure mode |
| **R-3** | **The star scale cannot be changed later.** 1–5 re-read as 1–10 is silently wrong for every existing row | Medium (permanent) | Decided now, deliberately, with the canvas already committed (D-230). If it is going to change, it changes **before** this merges — that is what approval means here |
| **R-4** | **The highlight FK breaks when setlist.fm data refreshes** | Medium | The snapshot is what renders; the FK is decoration (D-232, AC-5.4). `ON DELETE SET NULL` |
| **R-5** | **The past-only rule frustrates a real case** — someone writing about a festival on day two of three | Low | The concert is one row with one date; day two is `upcoming` until that date passes. Accepted: the note goes in on the way home, which is the moment this feature was designed for. If multi-day concerts become a real shape, they are their own change |
| **R-6** | **Reviews are personal writing in a project with no stated backup policy** | Medium | Flagged, not solved here. Cascade and erasure are covered (D-244); *backup* is an infrastructure concern this spec cannot close. See open question 1 |
| **R-7** | **`reviewSummary` grows the concert list payload for every user, reviewed or not** | Low | Three small fields, null when absent, no notes body (AC-6.2). Query count unchanged (AC-6.5) |
| **R-8** | **The nudge is annoying anyway** | Low | One card, one place, 30-day window, permanently dismissible, no notifications (D-242). If it still annoys, the window shrinks — a constant, not a redesign |

**Open questions — genuinely open, and not resolved by this document:**

1. **Backup policy for user-authored content.** The prompt raises it and it is correct to. There is
   no documented backup or point-in-time-recovery policy for the Postgres instance, and this is the
   first feature where the data is irreplaceable — a lost concert row can be re-entered from a ticket
   stub; a lost review cannot be re-written. **Recommendation:** ship this feature, and open a
   separate infrastructure prompt for backup/PITR before any public launch. This spec should not
   grow an infrastructure section, but it should be the reason that prompt gets written.
2. **Does an erasure request need to survive as a tombstone?** Spec 08's `AuditLogEntry` deliberately
   outlives the user it describes. A deleted review leaves an audit entry saying a review was
   deleted, with no content (D-243). **Recommendation:** that is correct as-is; confirm the operator
   agrees that "a review existed and is gone" is enough for an abuse investigation.

---

## Documentation to update in this branch

- **`docs/architecture.md`** — §10 data model sketch: add `ConcertReview` under `User`/`Concert`,
  remove `note` from the `Concert` field list, and cite `D-227`–`D-247`. §11: name `ConcertReview` in
  the erasure paragraph and record that its body is excluded from the backoffice and from audit
  values.
- **`docs/specs/2026-08-21-concert-domain-api.md`** — leave D-30 as written (it is a decision record,
  not live documentation) but note in this spec's header that D-30 is superseded here. Do **not**
  rewrite history.
- **OpenAPI** — regenerates from `ConcertReviewResource` and the changed concert DTOs. Do **not**
  list the endpoints in any README.
- **`frontend/README.md`** — one line: the concert page's note region is live, and the client's review
  types are generated.
- **No new environment variable**, no new external API, no new provider setting, no deployment change,
  no new sub-project.
- Run **`/doc-check`** against the diff before opening the PR.

---

## Recommendation Summary

| Decision | In one line |
|---|---|
| **D-227** | `ConcertReview` is its own entity and table, not four more columns on `Concert` — because prompt 21 must *exclude a row*, not remember to exclude columns |
| **D-228** | A singleton sub-resource, `GET`/`PUT`/`DELETE /api/concerts/{concertId}/review`, no review id in any URL. "A second review edits the first" becomes the only thing the endpoint can do |
| **D-229** | Two gates: `ConcertLocator` 404s a non-owner before `concert_reviews` is queried at all, then `ConcertReviewOwnerExtension` copies `ConcertOwnerExtension` verbatim |
| **D-230** | **Rating is 1–5 integer stars, nullable, no half steps.** Ratified from the prompt-06 canvas; permanent once anyone uses it |
| **D-231** | A review needs a rating or non-blank notes. Neither is a 422; a highlight alone does not count |
| **D-232** | **The highlight is both:** a nullable `Song` FK (`SET NULL`) *and* an always-populated title snapshot, which is the only thing ever rendered. Structured where possible, unbreakable always |
| **D-233** | A supplied highlight song must belong to this concert's lineup — otherwise the field is an id-probing oracle |
| **D-234** | **First writes are blocked on upcoming concerts** (422 `REVIEW_NOT_YET`, from D-24's existing status), and de-emphasized in the client with "unlocks after the show" copy |
| **D-235** | Three exemptions: the migration, editing, and deleting. The rule gates creation, never custody |
| **D-236** | Limits in **grapheme clusters** — notes 4000, highlight 200 — so a family emoji costs 1, not 7 |
| **D-237** | Plain text, no rendering contract (extends D-30), guarded by a static test aimed at the future commit that adds Markdown |
| **D-238** | **No `visibility` column.** Privacy is structural. Prompt 21 must decide sharing rather than find a flag waiting |
| **D-239** | The migration copies, count-verifies, then drops `concerts.note` in one transaction, with a `down()` that restores the text |
| **D-240** | `sourceNoteMigratedAt` records provenance — verifiable, precise on rollback, idempotent on re-run, never exposed |
| **D-241** | `ConcertOutput.reviewSummary` via one `LEFT JOIN` — the diary indicator with no N+1 and no notes body in list responses |
| **D-242** | The post-concert nudge is client-local (`AsyncStorage`), 30-day window, one card, permanently dismissible, **no push notifications** |
| **D-243** | The backoffice sees that a review exists and its rating — **never the body**, not even truncated. Same instinct as spec 08's digest-only audit values |
| **D-244** | Both FKs `ON DELETE CASCADE`; `UserEraser` needs no code change, and the test is what proves it |
| **D-245** | One `frontend/components/review/` module, width-breakpoint responsive, **no new platform fork** |
| **D-246** | Offline unchanged (D-37) — with one addition: a failed save keeps the draft in the open editor |
| **D-247** | Five test layers; the migration test runs against a real Postgres because text round-tripping is exactly what a mock would not catch |

---

## Review requested

The three questions the prompt left open are **closed** in this document, as instructed. They are the
three worth a deliberate yes or no before implementation starts, because two of them are expensive to
reverse afterwards:

1. **D-230 — 1–5 stars.** Ratified from the prompt-06 canvas rather than re-opened. This is the one
   decision that cannot be changed after anyone uses it: existing rows re-interpreted on a new scale
   are silently wrong. If a 1–10 scale or a three-word scale is wanted, now is the only free moment.
2. **D-232 — the highlight is both structured and free text.** The prompt framed these as
   alternatives; this spec argues they are not, and that a FK-only design would blank users'
   highlights overnight when the setlist cache refreshes. The cost is one extra column.
3. **D-234 / D-235 — blocked on the server, de-emphasized in the client, with three exemptions.** The
   exemptions matter more than the rule: the migration, editing and deleting are never blocked, so no
   existing text is refused and nobody is trapped with words they want to remove.

Two further decisions are worth an explicit look because they change something that already exists or
deliberately withhold something a later prompt might expect:

4. **D-239 — `concerts.note` is dropped, not deprecated.** This is a breaking API change in this
   branch, and it is the step that could lose data. The alternative (keep the column, stop writing it)
   leaves two writable homes for the same sentence.
5. **D-238 — no visibility flag ships.** Prompt 21 will need one shape or another for sharing, and
   this spec deliberately refuses to guess at it. If the intent is that prompt 20 should pre-build a
   per-review public toggle, say so now — it changes the table and the ownership tests.

**Open question 1 (backup policy for user-authored content)** is not resolved here and should not be:
it is an infrastructure prompt, and this feature is the reason to write it.
