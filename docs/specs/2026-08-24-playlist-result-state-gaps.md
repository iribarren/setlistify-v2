# FIX — Playlist result state: the two fields the UI was designed for and the API does not send

| | |
|---|---|
| **Spec ID** | `2026-08-24-playlist-result-state-gaps` |
| **Backlog prompt** | *(none — a fix, outside the numbered backlog; see `docs/prompts/fixes/README.md`)* |
| **Command** | `/bugfix playlist-result-state-gaps` |
| **Primary agent** | `backend-engineer` (DTO, mapper, enum) with `frontend-engineer` for the wiring |
| **Type** | **FIX — implementation follows this document directly.** One branch, one small migration (Q-2 resolved), one PR |
| **Depends on** | `13` playlist pipeline (approved) · `14` fast-mode backend (merged) · `16` fast-mode UI (**merged — this closes two of its known gaps**) |
| **Implemented by** | *(this is the implementation)* |
| **Decisions** | **D-182** – **D-187** |
| **Status** | **Approved 2026-08-24** |
| **Suggested branch** | `bugfix/playlist-result-state-gaps` *(final call: the user)* |

---

## Overview

### What this is

Prompt 16 shipped the fast-mode UI. Two of its acceptance criteria could not be met, not because the
client got them wrong, but because **the API does not carry the fact the screen is keyed on**. Both
are the same shape of gap: the pipeline knows something server-side, drops it before the DTO, and the
client is left inferring or defaulting.

- **Gap 1 — AC-6.6 is unsatisfiable.** `resultKind = no_source_material` is supposed to split into
  two designed screens by cause. The wire carries no cause, so the client defaults to one of them
  always. The deviation is already written down in the code, at
  `frontend/lib/playlist/view.ts` (the `case "no_source_material"` comment block), which names this
  document's fix in advance: *"the real fix is a backend field"*.
- **Gap 2 — AC-4.4 is half-wired.** `ResultNothing` is designed around one true thing: *we found what
  they played, we just could not find it on the provider*. The "View the setlist" affordance that
  says so has nothing to link to, because neither `PlaylistOutput` nor `PlaylistGenerationJobOutput`
  exposes the setlist.fm setlist the job actually resolved and matched against.
  `frontend/components/playlist/ResultCard.tsx` currently offers only "See the full breakdown".

Neither is new scope. Both are **already-designed, already-specified behaviour that shipped
incomplete**, which is why this is a `bugfix/` branch and not a feature.

### Why one spec and one branch, not two — D-182

They are bundled deliberately:

- Both are **the same edit to the same two files**: `backend/src/ApiResource/Playlist/PlaylistOutput.php`
  (and `…/PlaylistGenerationJobOutput.php`) plus `backend/src/State/PlaylistOutputMapper.php` (and
  `…/PlaylistGenerationJobOutputMapper.php`). Splitting them means two branches touching the same
  constructors, two `frontend/api/schema.d.ts` regenerations, and a guaranteed merge conflict in a
  generated file.
- Both are **one OpenAPI regeneration away from the client**. `CLAUDE.md`'s *"Regenerate before wiring
  up"* rule means each gap costs a regeneration cycle; doing them together costs one.
- Both are **discovered-together gaps in the same completed-job screen family** — `no_source_material`
  and `no_tracks_matched` are the two terminal "nothing to play" results, drawn on adjacent artboards.
- Both land as **backend DTO change + thin frontend wiring**, which `CLAUDE.md`'s *"One feature, one
  spec, one branch"* rule already requires be one PR each. Bundling makes it one PR total.

Anything larger — a new column, a new artboard, a new endpoint — would break the bundle and is
explicitly out of scope below.

### Load-bearing rules this fix does not reverse

| Rule (`CLAUDE.md`) | How this fix honours it |
|---|---|
| **setlist.fm responses are always cached** — `SetlistGateway` is the only door | This fix adds **zero** setlist.fm calls. Every value it exposes is already persisted: `Band::$setlistfmResolutionState`, `PlaylistTrack::$sourceSetlistfmId`, `Playlist::$reportSummary` |
| **Playlist generation degrades, it does not fail** | Both gaps are about making a *degraded* result more honest, never about turning one into an error. No new failure path, no new `FailureReason`, no new `BlockedReason` |
| **A user-scoped resource returns 404, never 403** | Untouched. The new fields ride on the existing `PlaylistItemProvider` / `PlaylistGenerationJobItemProvider`, behind `PlaylistOwnerExtension` / `PlaylistGenerationJobOwnerExtension` |
| **The streaming port is the only way to reach a provider** | Neither field is provider data. `sourceSetlists` is setlist.fm reference data; no provider symbol appears |
| **Generate types from OpenAPI, never hand-roll them** | Both new fields are typed backed enums / DTOs on the resource classes, so `frontend/api/schema.d.ts` regenerates them; the client aliases them (D-177) |
| **The backoffice is not part of the contract** | No `/admin` change. This is public-API only |
| **B**ackend owns persistence and sensitive logic | The setlist.fm URL is stored and served **backend-side** (D-186); the client never templates or constructs an external URL |

### Existing groundwork this fix builds on, not around

| Existing | Used how |
|---|---|
| `backend/src/Entity/Band.php` — `RESOLUTION_UNRESOLVED` / `RESOLUTION_RESOLVED` / `RESOLUTION_AMBIGUOUS` / `RESOLUTION_NO_PRESENCE`, written exclusively by `App\Service\Setlist\BandIdentityResolver` | **The cause for Gap 1 already exists and is already persisted.** Nothing needs to be discovered, only forwarded |
| `backend/src/Entity/Playlist.php` — `$reportSummary` is a plain `json` column with `addReportEntry(string $code, array $params, …)` | A new param on an existing entry is a **zero-migration** change |
| `backend/src/Entity/PlaylistTrack.php` — `$sourceSetlistfmId` (`source_setlistfm_id`, denormalized *"so the report is readable after a purge"*) and `$sourceBand` | **The setlist id for Gap 2 already exists on every track row.** The mapper drops it |
| `backend/src/Entity/PlaylistGenerationJob.php` — `$selectedSetlists` JSON (`{bandId, setlistfmId, selectionReason, fingerprint, songCount}`) | The corroborating record; a test asserts the two agree (T-6) |
| `backend/src/State/PlaylistGenerationJobOutputMapper.php` — already loads the `Playlist` via `$this->playlistRepository->findOneBy(['job' => $job])` | The fold in D-184 costs **no extra query** |
| The `34fe2f8` convention — *domain enums pass straight through to the DTO*, never `->value` | `NoSetlistCause` follows it exactly, so the OpenAPI union is generated and AC-5.4-style exhaustiveness holds client-side |
| `frontend/lib/playlist/view.ts` `derivePlaylistView()` | One branch is replaced; no new view kind, no new component, no new route |

---

## Problem and Fix — Gap 1: band-unknown vs. no-setlist-yet

### The problem, precisely

`backend/src/Service/Playlist/Stage/SetlistSelectionStage::run()` walks the lineup in stage order and,
for each band whose setlist could not be selected, records exactly this:

```php
$reportEntries[] = [ReportCode::NoSetlistForBand, ['band' => $band->getName()]];
```

`{ band }` and nothing else. Four materially different situations collapse into it:

| Situation | Where it is decided | State on `Band` at that moment |
|---|---|---|
| setlist.fm has no artist matching the typed name | `BandIdentityResolver::ensureResolved()` → `markNoPresence()`; `SetlistSelectionStage::fetchOnePage()` returns `[]` | `no_presence` |
| More than one plausible artist, so we refuse to guess (D-56/R-3) | `BandIdentityResolver` → `markAmbiguous()`; `fetchOnePage()` returns `[]` | `ambiguous` |
| The band **is** resolved; setlist.fm simply has no non-empty setlist for it — `SubstantialSetlistSelector::select()` returns `null` because `$nonEmpty === []` | `SubstantialSetlistSelector::select()` | `resolved` |
| Identity resolution could not run at all — setlist.fm unreachable or rate-limited (budget exhaustion throws `SetlistBudgetExhaustedException` instead), or `GENERATION_SETLIST_PAGES < 1` short-circuits `fetchOnePage()` before resolving | `BandIdentityResolver::unavailable()`, or the `setlistPages < 1` early return | `unresolved` |

`PlaylistPipeline::run()` then sees `0 === $job->getSongsTotal()` and freezes
`ResultKind::NoSourceMaterial` for all four alike. The client, having only the report code, defaults
to `degraded_no_songs` — which is a **lie in cases 1, 2 and 4**: `DegradedNoSongs.dc.html` renders a
"Known on setlist.fm" badge and *"Fan-submitted setlists often appear within a few days of the show"*,
telling the user to wait for a setlist that will never arrive because we never identified the band.

### The fix — D-183, D-184

**D-183 — a `NoSetlistCause` backed enum, written where the miss is decided.**

New file `backend/src/Service/Playlist/Model/NoSetlistCause.php`:

| Case | Value | Source condition, read at the `null === $result` branch in `SetlistSelectionStage::run()` |
|---|---|---|
| `BandUnknown` | `band_unknown` | `Band::RESOLUTION_NO_PRESENCE` |
| `BandAmbiguous` | `band_ambiguous` | `Band::RESOLUTION_AMBIGUOUS` |
| `NoSetlistForShow` | `no_setlist_for_show` | `Band::RESOLUTION_RESOLVED` — the band is known, the selector found nothing playable |
| `IdentityUnavailable` | `identity_unavailable` | `Band::RESOLUTION_UNRESOLVED` — we never got to ask |

`SetlistSelectionStage` reads it off the entity it already holds —
`$band->getSetlistfmResolutionState()`, authoritative and already flushed by `BandIdentityResolver`
inside `fetchOnePage()` — and widens the existing entry:

```php
$reportEntries[] = [ReportCode::NoSetlistForBand, [
    'band'  => $band->getName(),
    'cause' => NoSetlistCause::forResolutionState($band->getSetlistfmResolutionState())->value,
]];
```

**No new `ReportCode`, no new column, no migration.** `Playlist::$reportSummary` is a `json` column and
`ReportEntryOutput::$params` is already `array<string, mixed>` on the wire, so an added key is
backward-compatible with any client that ignores it. Rejected alternatives: two new report codes
(`NO_SETLIST_BAND_UNKNOWN` / `NO_SETLIST_FOR_SHOW`) — breaks the client's exhaustive `reportCopy`
`Record` for a distinction that is not a *sentence*, it is a *screen*; a `bandKnown` boolean — throws
away cases 2 and 4, which are the ones we most want to be able to split later.

**D-184 — one typed, job-level `noSetlistCause` on both output DTOs, derived in the mappers, not stored.**

A report param is `mixed` in the generated schema; the client's `derivePlaylistView()` needs a field it
can switch on exhaustively (the `34fe2f8` reasoning, applied here). So:

- `PlaylistGenerationJobOutput` gains `public ?NoSetlistCause $noSetlistCause` — non-null **only** when
  `resultKind === ResultKind::NoSourceMaterial`, null in every other state.
- `PlaylistOutput` gains the same field, for the same reason `resultKind` is already mirrored there.

Both mappers derive it by folding the `NO_SETLIST_FOR_BAND` entries in `Playlist::getReportSummary()`.
`PlaylistGenerationJobOutputMapper` already loads that `Playlist`, so this costs no extra query.

**The fold rule**, stated exactly because a multi-band lineup can mix causes and every band failed by
definition:

1. If **any** entry's cause is `no_setlist_for_show` → `no_setlist_for_show`. Rationale: at least one
   band on the bill *is* known on setlist.fm, so `DegradedNoSongs`'s "Known on setlist.fm" badge and its
   "check back in a few days" promise are both truthful.
2. Otherwise, take the **last** `NO_SETLIST_FOR_BAND` entry's cause. `SetlistSelectionStage` iterates
   `array_reverse($kept)` — support acts first, headliner last — so the last entry is the headliner's,
   and the headliner is the band the user cares about naming.
3. No entries (or the playlist row is gone) → `null`, and the client falls back exactly as it does today.

A shared `App\Service\Playlist\NoSetlistCauseFolder` holds the rule so both mappers call one
implementation and one test covers it.

**Frontend — D-187**, one branch replaced in `frontend/lib/playlist/view.ts`:

```ts
case "no_source_material":
  return view(
    job.noSetlistCause === "no_setlist_for_show" ? "degraded_no_songs" : "degraded_band_unknown",
    job, playlist,
  );
```

The `KNOWN SPEC DEVIATION` comment block is deleted with it. `band_ambiguous` and
`identity_unavailable` both route to `DegradedBandUnknown` for now (see Q-1) — its copy, *"This band
isn't in setlist.fm's database yet — that happens with smaller…"*, is honest for `band_unknown` and
tolerable for the other two; a `null` cause keeps today's `degraded_no_songs` default, so an older
server cannot break a newer client. No new view kind, no new artboard, no new component.

---

## Problem and Fix — Gap 2: `ResultNothing` has nothing to link to

### The problem, precisely

`result_nothing` is reached when `resultKind = no_tracks_matched` — `PlaylistPipeline::run()` counted
zero hits after matching and returned **without creating a provider playlist** (D-135). So:

- `PlaylistOutput::$externalUrl` is `null` — there is no provider playlist. `ResultCard.tsx` correctly
  disables "Open in \<Provider\>" for this variant.
- The setlist **does** exist and was matched against — `Playlist::$tracks` is fully populated, one row
  per source song, each carrying `PlaylistTrack::$sourceSetlistfmId`.
- But `PlaylistOutputMapper::map()` builds `PlaylistTrackOutput` **without** `sourceSetlistfmId`, and
  `PlaylistOutput` has no setlist field at all. The one thing that succeeded is invisible on the wire.

`PlaylistGenerationJob::$selectedSetlists` also holds `setlistfmId` per band, and is likewise never
mapped.

### The fix — D-185, D-186

**D-185 — `PlaylistOutput` gains `sourceSetlists: list<SourceSetlistOutput>`.**

New DTO `backend/src/ApiResource/Playlist/SourceSetlistOutput.php`:

```php
final readonly class SourceSetlistOutput
{
    public function __construct(
        public string $bandName,
        public string $setlistfmId,
        public ?string $url,
    ) {}
}
```

Built in `PlaylistOutputMapper::map()` by grouping the tracks it **already iterates** on
`(sourceBand, sourceSetlistfmId)`, preserving first-appearance (playing) order:

- No new query, no new repository call.
- Correct for the multi-band case: two bands, two entries, in stage order.
- Correct for `no_source_material`: no tracks → empty list → nothing to link to, which is the truth.
- Independent of `PlaylistGenerationJob::$selectedSetlists`, so it survives the job row's JSON being
  null on a resumed/legacy row — while T-6 asserts the two agree when both are present.

Deliberately **not** included: `eventDate`, `venueName`, `songCount`, `selectionReason`. All four
already reach the client on the existing `SELECTED_FROM` report entry
(`SetlistSelectionStage` writes `band`, `date`, `venue`, `songCount`, `selectionReason`), and
duplicating them into a second shape is how two sources of truth start.

**D-186 — the setlist.fm URL is persisted, not constructed. Q-2 is resolved: the id-only form does not
resolve.**

Verified before implementation, per T-12's spirit: `https://www.setlist.fm/setlist/{id}.html` returns
**404**; only the canonical slug form
(`https://www.setlist.fm/setlist/<artist>/<year>/<venue-city>-<id>.html`) resolves (200), confirmed
against a real, current setlist. The id-only form was the only path that stayed migration-free, so this
fix now carries one small migration.

`Setlist` gains a nullable `url` column, populated from the setlist.fm API response's own `url` field
(the payload already carries it — `SetlistNormalizer` already reads the equivalent field for artist
search candidates, at `ArtistSearchCandidate::$url`; `hydrateOne()` currently drops it for setlists) at
fetch time in `SetlistGateway`/`SetlistNormalizer::hydrateOne()`. **No backfill**: per D-59, `Setlist` is
immutable once fetched, so rows cached before this branch keep `url = null` permanently — never
re-fetched to populate it, since a cache hit must never re-call setlist.fm to satisfy a UI nicety.

`PlaylistOutputMapper` reads `$track->getSourceSetlist()?->getUrl()` (or the equivalent lookup already
available where the track's setlist is resolved) when building each `SourceSetlistOutput`; a null URL
means the entry is built with `url: null` and `SourceSetlistOutput.url` becomes `?string`.

The client (D-187, `ResultCard.tsx`) renders "View the setlist" only when `sourceSetlists[0]?.url` is
non-null — absent, never disabled, exactly as AC-2.5 already required for an empty list. A setlist
cached before this branch ships with no button, which is the honest state: pointing at a 404 would be
worse than not offering it.

A static test still asserts no `setlist.fm` URL is constructed or templated in `frontend/lib/playlist/`
or `frontend/components/playlist/` — the client only ever renders a URL the backend already built.

**Frontend — D-187**, `ResultCard.tsx` gains, for `result_nothing` only, a secondary
**"View the setlist"** button opening `sourceSetlists[0].url` via `Linking.openURL`, in the slot the
other variants use for "Open in \<Provider\> anyway". Absent or empty `sourceSetlists` → the button is
not rendered (never rendered disabled: an affordance that cannot work should not be drawn).

---

## Acceptance Criteria

**Gap 1**

- **AC-1.1** `SetlistSelectionStage` writes a `cause` param on every `NO_SETLIST_FOR_BAND` entry, whose
  value is one of the four `NoSetlistCause` cases, derived from `Band::$setlistfmResolutionState` at the
  moment of the miss. No setlist.fm call is made to obtain it.
- **AC-1.2** `PlaylistGenerationJobOutput.noSetlistCause` and `PlaylistOutput.noSetlistCause` are
  non-null exactly when `resultKind` is `no_source_material`, and null in every other state — including
  `partial`, `complete` and `no_tracks_matched`.
- **AC-1.3** The fold follows D-184's three rules exactly, including the "last entry is the headliner"
  ordering that `array_reverse($kept)` produces.
- **AC-1.4** Both fields appear in the generated OpenAPI document as a **string enum** with all four
  values, not a bare string (the `34fe2f8` rule).
- **AC-1.5** `derivePlaylistView()` returns `degraded_no_songs` only for `no_setlist_for_show`, and
  `degraded_band_unknown` for `band_unknown`, `band_ambiguous` and `identity_unavailable`. A `null`
  cause preserves today's `degraded_no_songs` behaviour.
- **AC-1.6** **AC-6.6 of spec 16 is satisfiable and satisfied**, and its `KNOWN SPEC DEVIATION` comment
  block in `frontend/lib/playlist/view.ts` is deleted, not amended.
- **AC-1.7** Neither degraded screen is styled as an error — spec 16's AC-4.3 assertion still passes over
  both.

**Gap 2**

- **AC-2.1** `PlaylistOutput.sourceSetlists` lists one entry per distinct `(sourceBand,
  sourceSetlistfmId)` among the playlist's tracks, in first-appearance order, each with `bandName`,
  `setlistfmId` and `url` (nullable).
- **AC-2.2** It is populated on every playlist that has tracks — `complete`, `partial` and
  `no_tracks_matched` alike, not only on `result_nothing`.
- **AC-2.3** It is an empty list, never null, when the playlist has no tracks (`no_source_material`).
- **AC-2.4** Building it adds no database query and no setlist.fm call to `GET /api/playlists/{id}`.
- **AC-2.5** `result_nothing` renders a **"View the setlist"** secondary action that opens
  `sourceSetlists[0].url` **only when it is non-null**; the button is absent — never disabled, never
  pointing at a 404 — when the list is empty or its `url` is null (a setlist cached before this branch).
  **AC-4.4 of spec 16 is satisfied for every setlist cached from this branch onward.**
- **AC-2.6** No setlist.fm URL is constructed or templated anywhere in `frontend/`; a static test asserts
  it. The client only ever renders a URL the backend already persisted.
- **AC-2.7** `Setlist.url` is populated from the setlist.fm API response's own `url` field at fetch time,
  with **no backfill call** to setlist.fm for rows cached before this branch — they keep `url = null`
  permanently, per D-59's immutable-once-fetched rule.

**Both**

- **AC-3.1** Exactly one migration is added — a nullable `url` column on `Setlist` — and nothing else in
  this branch requires one. `doctrine:schema:validate` is clean after it.
- **AC-3.2** `frontend/api/schema.d.ts` is regenerated in this branch **before** any client code is
  edited, and every new client type is an alias of `components["schemas"][…]` (D-177).
- **AC-3.3** Spec 16's D-170 table row for `no_source_material` is rewritten in this branch to name
  `noSetlistCause` as the driving field, per its own rule: *"Where implementation reveals one of those
  documents was wrong, it is corrected in that document, in this branch."* Spec 14 §6's DTO listing is
  updated the same way.
- **AC-3.4** Every pre-existing playlist test still passes unchanged; no existing field is renamed,
  removed or retyped.

---

## Technical Approach

### Files touched

| File | Change |
|---|---|
| `backend/src/Service/Playlist/Model/NoSetlistCause.php` | **New.** Backed enum, four cases, plus `forResolutionState(string): self` mapping the `Band::RESOLUTION_*` constants |
| `backend/src/Service/Playlist/Stage/SetlistSelectionStage.php` | One line: add `'cause' => …` to the `ReportCode::NoSetlistForBand` params at the `null === $result` branch |
| `backend/src/Service/Playlist/NoSetlistCauseFolder.php` | **New.** `fold(array $reportSummary): ?NoSetlistCause` — D-184's three rules, one implementation |
| `backend/src/ApiResource/Playlist/PlaylistGenerationJobOutput.php` | `+ public ?NoSetlistCause $noSetlistCause` |
| `backend/src/ApiResource/Playlist/PlaylistOutput.php` | `+ public ?NoSetlistCause $noSetlistCause`, `+ /** @var list<SourceSetlistOutput> */ public array $sourceSetlists` |
| `backend/src/ApiResource/Playlist/SourceSetlistOutput.php` | **New.** `bandName`, `setlistfmId`, `url` |
| `backend/src/State/PlaylistGenerationJobOutputMapper.php` | Fold the already-loaded `$playlist`'s report summary; pass the enum instance through, never `->value` |
| `backend/src/State/PlaylistOutputMapper.php` | Same fold; plus group the tracks it already iterates into `sourceSetlists` |
| `backend/src/Entity/Setlist.php` | `+ ?string $url` (nullable column) |
| `backend/migrations/VersionXXXX_add_setlist_url.php` | **New.** One nullable column, no backfill |
| `backend/src/Service/Setlist/SetlistNormalizer.php` | `hydrateOne()` reads the payload's existing `url` field for setlists too (Q-2, resolved) |
| `frontend/api/schema.d.ts` | Regenerated — **first task on the branch** |
| `frontend/lib/playlist/types.ts` | Aliases for the two new generated shapes |
| `frontend/lib/playlist/view.ts` | The `no_source_material` branch; delete the deviation comment |
| `frontend/components/playlist/ResultCard.tsx` | The `result_nothing` "View the setlist" action |

### Order of work

1. Backend: enum, folder, DTO fields, mappers, `Setlist.url` migration, `SetlistNormalizer` change,
   backend tests.
2. Bring the stack up (`docker compose up -d`) and **regenerate** `frontend/api/schema.d.ts` from the
   running `/api/docs` — `CLAUDE.md`'s *"Regenerate before wiring up"*.
3. Frontend: types, `view.ts` branch, `ResultCard.tsx` action (null-`url`-aware), frontend tests.
4. Amend spec 16 (D-170 table, AC-6.6, AC-4.4 status) and spec 14 §6's DTO listing.

### Why one migration, and only one

Gap 1 needs none: `Band::$setlistfmResolutionState` (a mapped column), `Playlist::$reportSummary` (a
`json` column with an append API) both already exist. Gap 2's `setlistfmId` is likewise already on
`PlaylistTrack::$sourceSetlistfmId` — but the **URL** it needs to be useful is not persisted anywhere,
and confirmed (D-186) not derivable from the id alone: setlist.fm's canonical URL is slug-based and the
id-only form 404s. The one column this branch adds is the smallest fix that keeps the backend, not the
client, as the source of truth for an external URL — consistent with `SetlistGateway` being the only
door to setlist.fm.

---

## Out of Scope

| Not here | Why / where |
|---|---|
| A dedicated artboard for `band_ambiguous` or `identity_unavailable` | Undesigned. They route to `DegradedBandUnknown` today; the enum makes splitting them a one-line change once prompt 15 draws them. See Q-1 |
| A backoffice screen for ambiguous bands, or a user-facing "pick the right band" flow | Prompt 09's AC-11.5 already owns the audited admin correction; a user-facing disambiguation is new product scope |
| **Backfilling** `url` for setlists cached before this branch | D-59 makes `Setlist` immutable once fetched; a backfill would mean re-calling setlist.fm for a UI nicety, which is exactly the budget spend the caching rule exists to prevent. Old rows stay `url = null` and the button stays absent for them (AC-2.7) |
| Exposing `eventDate` / `venueName` / `songCount` / `selectionReason` on `sourceSetlists` | Already on the wire via the `SELECTED_FROM` report entry (D-185) |
| An in-app setlist viewer | `ResultNothing` links **out** to setlist.fm. An in-app view is prompt 17's `SetlistSelect` territory |
| A new `ReportCode`, a new `BlockedReason`, a new `FailureReason`, a new job state | Nothing here is a new failure mode |
| Any change to `derivePlaylistView()`'s other fifteen cases, to polling, or to the report catalogue | Spec 16 shipped them; this touches one branch |
| Retro-populating `cause` on report entries written before this branch | Old rows fold to `null` and keep today's behaviour (AC-1.5). Not worth a data migration for a screen |

---

## Dependencies

| # | Dependency | Status |
|---|---|---|
| 1 | Spec 16 (`docs/specs/2026-08-24-playlist-fast-mode-ui.md`) merged — this amends its D-170 table and closes AC-4.4 / AC-6.6 | Approved 2026-08-24 |
| 2 | Spec 14's DTOs and mappers on `master`, post-`34fe2f8` (enums pass through untouched) | Merged |
| 3 | Spec 09's `Band::$setlistfmResolutionState` and `BandIdentityResolver` as the sole writer of it | Merged |
| 4 | `docker compose up` reachable so `/api/docs` can be regenerated from (AC-3.2) | Required, first task |
| 5 | **Q-2 answered** — confirmed 2026-08-24: the id-only setlist.fm URL form 404s, the slug form resolves. This branch carries the one-column migration accordingly | **Resolved** |

---

## Risks

| # | Risk | Mitigation |
|---|---|---|
| R-1 | **A setlist cached before this branch has no `url`**, so its "View the setlist" button would 404 if rendered anyway | AC-2.5/AC-2.7: the button is rendered only when `url` is non-null, never disabled. Confirmed pre-implementation that the id-only form 404s (D-186), so no code path can construct a working URL from the id alone — the null-check is the only guard needed |
| R-2 | **The fold picks the wrong band on a mixed multi-band lineup**, e.g. saying "known on setlist.fm" because the opener is known while the headliner is not | D-184 rule 1 is deliberate and its rationale is written down: the badge claims *a* band is known, not *the* band. T-3 covers the mixed case explicitly. Revisit only if the copy is made band-specific |
| R-3 | **`identity_unavailable` is a transient condition rendered as a permanent one** — setlist.fm was briefly unreachable, and the user is told the band isn't in the database | Real, and the reason the enum keeps it as its own case rather than folding it into `band_unknown`. Splitting the screen is a one-line change once drawn (Q-1). Note the common transient case, budget exhaustion, does **not** reach here: it throws `SetlistBudgetExhaustedException` and blocks the job |
| R-4 | **Adding a field to a polled DTO** — `PlaylistGenerationJobOutput` is fetched up to ~20 times per generation | Both fields are null on every active state; the fold reads an already-loaded entity's JSON column. No new query, no `ETag` change (D-150's ETag is `id-state-songsProcessed-updatedAt`, all unaffected) |
| R-5 | **A stale generated client** — the frontend edited before `schema.d.ts` is regenerated, so the new fields are `any` and the exhaustive switch silently passes | AC-3.2 makes regeneration the first task; T-8 asserts every new client type traces to `schema.d.ts` |
| R-6 | **Scope creep into prompt 17.** "View the setlist" is one step from "choose a different setlist" | Out of Scope names it. This branch links out; it renders no setlist |

---

## Test Plan

Every item names its kind. No test makes an outbound setlist.fm or provider call.

| # | Kind | Asserts |
|---|---|---|
| T-1 | Unit (PHP) | `NoSetlistCause::forResolutionState()` maps all four `Band::RESOLUTION_*` constants, and throws on an unknown state rather than silently defaulting |
| T-2 | Integration (PHP) | `SetlistSelectionStage`: a `no_presence` band, an `ambiguous` band, a `resolved` band with only empty setlists, and an `unresolved` band each produce a `NO_SETLIST_FOR_BAND` entry with the matching `cause` |
| T-3 | Unit (PHP) | `NoSetlistCauseFolder`: single entry each of the four causes; a mixed lineup where one band is `no_setlist_for_show` → `no_setlist_for_show`; a mixed lineup with no resolved band → the **last** entry's cause; an empty summary → `null` |
| T-4 | Integration (PHP) | `GET /api/playlist-generation-jobs/{id}` on a `no_source_material` job returns `noSetlistCause`; on a `partial`, `complete` and `no_tracks_matched` job it is `null` |
| T-5 | Unit (PHP) | `PlaylistOutputMapper` builds `sourceSetlists`: one band; two bands (order = first appearance); duplicate ids collapse to one entry; zero tracks → `[]` |
| T-6 | Unit (PHP) | `sourceSetlists`' ids agree with `PlaylistGenerationJob::$selectedSetlists` when the latter is present; a null `selectedSetlists` does not affect the output |
| T-7 | Unit (PHP) | `SetlistNormalizer::hydrateOne()` persists the payload's `url` field onto `Setlist::$url`; a payload with no `url` key leaves it `null` rather than erroring |
| T-8 | Static (TS) | No `setlist.fm` URL literal or template in `frontend/lib/playlist/` or `frontend/components/playlist/`; every new type traces to `frontend/api/schema.d.ts` |
| T-9 | Unit (TS) | `derivePlaylistView()`: `no_setlist_for_show` → `degraded_no_songs`; `band_unknown`, `band_ambiguous`, `identity_unavailable` → `degraded_band_unknown`; `null` → `degraded_no_songs`. Extends spec 16's existing T-1 fixture table |
| T-10 | Component (TS) | `result_nothing` renders "View the setlist" and opens `sourceSetlists[0].url`; with `sourceSetlists: []` the button is **absent**, not disabled |
| T-11 | Component (TS) | Spec 16's AC-4.3 assertion still passes over `degraded_band_unknown` and `degraded_no_songs` — no error token, no error icon, none of the forbidden words |
| T-12 | Manual | Against a running stack: a concert with a deliberately misspelled headliner reaches `DegradedBandUnknown`; a real, obscure band with no logged setlist reaches `DegradedNoSongs`; a `result_nothing` job generated **after this branch** shows a "View the setlist" button that opens a real, resolving setlist.fm page; a `result_nothing` job whose setlist was cached **before** this branch shows no button |

---

## Documentation to update, in this branch

- `docs/specs/2026-08-24-playlist-fast-mode-ui.md` — D-170's `no_source_material` rows name
  `noSetlistCause`; AC-6.6 and AC-4.4 recorded as satisfied, with a pointer to this spec.
- `docs/specs/2026-08-23-playlist-fast-mode-backend.md` §6 — the two DTOs' field listings.
- The OpenAPI document regenerates itself from the resource classes — **no endpoint is documented
  anywhere by hand**.
- No new environment variable, no `.env.example` change, no backoffice change. One migration (the
  nullable `Setlist.url` column) — noted here, not hidden in a diff.
- `docs/prompts/fixes/README.md` — add and then delete this fix's row on merge, if the user wants it
  tracked there (see Q-3).

---

## Risks and Open Questions

1. **Should `band_ambiguous` and `identity_unavailable` get their own screens?**
   The enum carries four causes; prompt 15 drew two screens. Today all of `band_unknown`,
   `band_ambiguous` and `identity_unavailable` land on `DegradedBandUnknown`, whose copy (*"isn't in
   setlist.fm's database yet"*) is precisely true only for the first. `band_ambiguous` deserves
   something closer to *"more than one band goes by this name — we won't guess"*, and
   `identity_unavailable` closer to *"we couldn't reach setlist.fm to check — we'll try again"*.
   **Recommendation: ship all three on `DegradedBandUnknown` now, and carry the enum anyway.** The
   backend field is the expensive half; splitting the screen later is one line plus an artboard, and
   this branch is a bugfix, not a design pass. Raise a prompt-15 follow-up if the ambiguous case turns
   out to be common.

2. **What URL should the client be given? — Resolved 2026-08-24, before implementation.**
   Checked directly: `https://www.setlist.fm/setlist/1b41f9e4.html` (id-only) → **404**;
   `https://www.setlist.fm/setlist/my-chemical-romance/2026/nationals-park-washington-dc-1b41f9e4.html`
   (the real slug form, same id) → **200**. The id-only form does not resolve, so it is not an option.
   **Resolution: persist the payload's own `url` field on a new nullable `Setlist.url` column**,
   populated in `hydrateOne()` going forward, no backfill (D-59), `sourceSetlists[].url` is `?string`,
   and the button is absent — never disabled — when it is null (AC-2.5, AC-2.7). This is now written
   into D-186 and costs this branch its one migration.

3. **Should this be recorded as a fix prompt in `docs/prompts/fixes/`?**
   The existing five (A–E) are prompts written *before* their spec. This one has a spec already.
   **Recommendation: skip the prompt file** — the spec is the artifact, and `fixes/README.md`'s
   *"delete a file once its work is merged"* rhythm adds churn for a branch that starts immediately.
   Add a row only if the work is being queued rather than started.

4. **Branch name.**
   **Recommendation: `bugfix/playlist-result-state-gaps`** — `bugfix/`, not `feature/`, because both
   items are already-designed behaviour that shipped incomplete, and `CLAUDE.md`'s bug-fix workflow
   (diagnose → branch → fix and test → commit) is the one that fits. Final call: the user.

---

## Review requested

Q-2 is resolved (verified 2026-08-24: the id-only setlist.fm URL 404s; the slug form resolves), so this
branch now carries one migration — the nullable `Setlist.url` column — instead of shipping migration-free.
Two decisions still worth pushing back on before implementation:

- **D-184 — deriving `noSetlistCause` in the mapper rather than persisting it on the job.** Keeps the
  report the single source of truth, at the cost of recomputing a small fold on every poll. The
  alternative — a `no_setlist_cause` column on `playlist_generation_jobs`, frozen alongside `resultKind`
  in `freezeCounters()` — is arguably cleaner and costs a second migration.
- **D-185 — deriving `sourceSetlists` from the track rows rather than from
  `PlaylistGenerationJob::$selectedSetlists`.** The tracks are the rows that actually got matched, and
  they need no extra query; the job JSON is the record of intent. They should always agree (T-6), but
  if they ever disagree, this choice decides which one the user sees.
