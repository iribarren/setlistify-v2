---
name: notes_and_reviews_ui
description: Notes-and-reviews feature (feature/notes-and-reviews) — RNTL render()/renderHook() async gotchas, the SongOutput.id backend gap, module layout
metadata:
  type: project
---

Shipped in `feature/notes-and-reviews` (spec `docs/specs/2026-08-26-notes-and-reviews.md`,
D-227–D-247). Builds on [[frontend_stack]], [[frontend_tooling_gotchas]], [[playlist_fast_mode_ui]].

**`render()` itself must be awaited in this RNTL v14 setup, not just `fireEvent.*`/`renderHook()`.**
Skipping `await` on `render(...)` doesn't throw at the call site — it fails later with the cryptic
`` `render` function has not been called `` from `screen.queryByTestId`/`getByText`, because the
`screen` binding isn't attached yet. `PlaylistSection.test.tsx` already did `await renderSection()`
consistently; my first pass at the review tests didn't, and every affected file either hung the
whole `jest` process indefinitely (no output at all, even non-`--silent`, killed only by an external
timeout) or failed every assertion with that exact message. Fix: `await render(...)` everywhere,
including inside small helper functions that wrap it.

**A hook that calls `setState` synchronously in a plain event handler invoked directly from a test
(`result.current.dismiss()`) needs `await act(async () => { result.current.dismiss(); })`** — bare
calls produce "not wrapped in act(...)" warnings AND can corrupt a LATER, unrelated test's
`renderHook()` (its `result.current` comes back `null` even though the hook itself is fine) via
"overlapping act() calls" — the warning is not cosmetic, it desyncs the next test in the same file.
Same for `unmount()` when the hook holds pending async work.

**`useReviewPromptCard(pastConcerts, ready)` takes an explicit `ready: boolean` rather than inferring
readiness from the concerts array being non-empty** — the hook must be called unconditionally every
render (rules of hooks), including during the list screen's own loading state, when `pastConcerts` is
still `[]`. Without a caller-supplied `ready` flag, the one-shot pick (AC-7.3: evaluated once per
mount) would resolve permanently to "no candidate" against the empty pre-load array. Caller passes
`Boolean(past.data)` from `useConcertsSection("past")`.

**Never compare a derived ARRAY for equality across renders as a proxy for "did the underlying data
change"** — `pastReviewPromptCandidates(...)` returns a fresh array every call, so
`if (previousArray !== currentArray)` in a render body is unconditionally true every render →
infinite `setState`-during-render loop ("Too many re-renders", and it broke THREE unrelated tests in
`concerts-list.test.tsx` transitively, since the crash happened on any render of that screen). Fix:
derive a stable BOOLEAN/primitive first (`stillCandidate = candidates.some(...)`) and compare THAT
via the project's existing "second `useState` + compare in render body" pattern (see
[[concert_tracker_ui]]) — comparing primitives settles; comparing collections never does.

**Backend gap found and fixed as part of this feature, not before it**: the generated
`SongOutput.jsonld` schema had no entity id at all — nothing to submit as
`ConcertReviewInput.highlightSongId` for the structured highlight picker (D-232/US-5). Added
`?int $id` to `backend/src/ApiResource/Setlist/SongOutput.php`, populated from `Song::getId()` on the
persisted path in `SetlistDetailProvider::fromEntity()`, left `null` on the raw-payload fallback path
(no relational `Song` row exists there). Regenerate-the-client discipline caught this immediately —
worth checking a DTO's actual fields (not just its existence) before assuming a frontend feature is
buildable against it.

**Module layout** (mirrors [[playlist_fast_mode_ui]]'s shape): `frontend/lib/review/` (`types.ts`,
`grapheme.ts` — `countGraphemes()` via `Intl.Segmenter` with `[...str].length` fallback,
`highlightSources.ts` — `useHighlightSources()`, two-step `GET /api/bands/{bandId}/setlists` →
`GET /api/setlists/{setlistfmId}` matched by the concert's own date, zero `SetlistGateway` calls,
`prompt.ts` — the D-242 pure eligibility function + `useReviewPromptCard()`,
`reviewPromptStorage.native.ts`/`.web.ts` — `AsyncStorage`/`localStorage`, same platform-suffix shape
as `lib/playlist/choicesStorage.*`), `frontend/components/review/` (`ReviewSection`, `ReviewEditor`,
`StarRating`, `HighlightPicker`, `ReviewPromptCard`), `frontend/hooks/useConcertReview.ts` (new
top-level `hooks/` dir — this spec named this exact path).

**Import-cycle trap**: `ConcertCard` (in `components/concert/`) renders this feature's `StarRating`
(in `components/review/`) for the list indicator. `ReviewEditor`/`ReviewSection` need
`DisclosureSection`/`DeleteConfirmation` FROM `components/concert/` — importing those via the
`@/components/concert` BARREL (which re-exports `ConcertCard`) would form a cycle. Fixed by importing
the concrete files (`@/components/concert/DisclosureSection`, `@/components/concert/DeleteConfirmation`)
instead of the barrel, in the review module only.
