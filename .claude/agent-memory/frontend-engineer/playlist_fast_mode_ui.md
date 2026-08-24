---
name: playlist_fast_mode_ui
description: Playlist fast-mode UI (feature/playlist-fast-mode-ui) — the now-resolved openapi-typescript enum gap, openapi-fetch header/304 handling, renderHook is async, module layout
metadata:
  type: project
---

Shipped in `feature/playlist-fast-mode-ui` (spec `docs/specs/2026-08-24-playlist-fast-mode-ui.md`,
D-161–D-181). Builds on [[frontend_stack]], [[frontend_tooling_gotchas]], [[streaming_account_linking]].

**Backend DTO gap (RESOLVED 2026-08-24 on `bugfix/playlist-report-enum-typing`, merged into
`feature/playlist-fast-mode-ui`): PHP backed enums do NOT survive into `openapi-typescript`'s output
unless the output DTO property is typed with the enum itself** — the general lesson still applies to
any *future* DTO. `PlaylistGenerationJobOutput::$state` / `$blockedReason` / `$failureReason` /
`$resultKind` and `PlaylistTrackOutput::$outcome` / `$reasonCode` (plus `ReportEntryOutput::$code`)
were declared `?string` in the output DTOs even though the underlying Doctrine columns are
`enumType:`-backed PHP enums (`backend/src/Service/Playlist/Model/*.php`); fixed by typing the DTO
properties with the enum itself (see [[project_playlist_output_dto_enums]] for the backend-side
gotchas hit fixing it — `->value` must be skipped, `api:openapi:export` needs `cache:clear` first).
`frontend/api/schema.d.ts` now carries real `enum` arrays for all seven fields, matching
`ProviderConfigOutput::$playbackMode`'s existing `"embed" | "deeplink" | "off"` shape.
`frontend/lib/playlist/types.ts` no longer hand-transcribes these unions — `JobState`/`BlockedReason`/
etc. are now `NonNullable<PlaylistGenerationJobOutput["state"]>`-style aliases of the generated
fields, with the runtime arrays (`JOB_STATES` etc.) still compile-time-checked against those aliases
via a small `exhaustiveArray()` helper (so a backend enum change still fails the frontend build until
the array is updated) — same guarantee as before, now for real instead of by hand.
**How to apply:** if this pattern resurfaces on a *different* output DTO (a spec needing a
compile-time-exhaustive `Record` keyed by a wire enum, e.g. D-167's report-code catalogue), check
first whether the DTO property is typed with the PHP enum — that's the actual fix, not a
hand-transcribed union in the frontend.

**Two designed screens can't be distinguished with the API's current fields.** D-170/AC-6.6 asks the
client to render `DegradedBandUnknown` vs. `DegradedNoSongs` based on *why* `resultKind =
no_source_material` (band unresolved on setlist.fm vs. band resolved but no setlist logged for the
show) — but the only signal on the wire, the job-level `NO_SETLIST_FOR_BAND` report entry
(`backend/src/Service/Playlist/Stage/SetlistSelectionStage.php`), carries `{ band: name }` only, no
resolution-state flag. `derivePlaylistView()` defaults to `degraded_no_songs` (the milder framing)
and documents this inline. Also: `ResultNothing.dc.html`'s "View the setlist" action has nothing to
link to — neither job nor playlist output exposes the selected setlist's `setlistfmId`.

**`openapi-fetch`'s per-call `RequestOptions.headers` is independent of the operation's typed
`params.header`** — even when a schema operation declares `header?: never` (no request headers
modeled in the OpenAPI doc, which is the case for every operation here — no `If-None-Match` is
documented), you can still pass `headers: { "If-None-Match": etag }` in the call options and it goes
out on the wire; `tsc` does not reject it. Needed for D-164's conditional GET.

**A 304 response is NOT `response.ok`** (only 200–299 is) — `openapi-fetch`'s `unwrap()` helper
throws on any non-ok status, so polling code that needs to handle 304 specially must call
`apiClient.GET()` directly (not through `unwrap`) and branch on `response.status === 304` before
checking `error`/`ok`. `usePlaylistJobPolling` (`lib/playlist/polling.ts`) does this, returning the
previously-cached query data by reference on a 304 so nothing re-renders (AC-2.4).

**`@testing-library/react-native` v14's `renderHook` is ALSO async**, same family as `render()`/
`fireEvent.*` already in [[frontend_tooling_gotchas]] — `const { result } = await renderHook(...)`,
not `const { result } = renderHook(...)`. Omitting `await` fails with `Cannot read properties of
undefined (reading 'current')` on the very next line, not inside `renderHook` itself, which makes
the real cause easy to miss.

**Module layout** (mirrors [[streaming_account_linking]]'s shape): `frontend/lib/playlist/`
(`queries.ts`, `polling.ts`, `view.ts` — `derivePlaylistView()`, the one state→screen mapping,
`MOSTLY_MATCHED_FLOOR = 0.5` — `reportCopy.ts`, `providerChoice.ts`, `types.ts`, `index.ts`),
`frontend/components/playlist/` (`GenerateTrigger`, `GenerationProgress`, `ResultCard`, `ReportList`,
`DegradedState` — six degraded + two genuine `failed` screens in one file, `ErrorState` used ONLY for
the two failures — `PlaylistSection`, `DeletePlaylistConfirmation`). Routes:
`app/(app)/concerts/[id]/playlist.tsx` (ONE route for progress + all sixteen result/degraded/failure
views, state-driven) and `playlist-report.tsx`.

**Static test pattern for "no provider key literal in this directory"** (T-16): walk the two
directories' `.ts`/`.tsx` files with `node:fs`, regex for `["'\`](spotify|youtube|...)["'\`]` — cheap,
fast, and catches what a code reviewer would otherwise have to eyeball.

**Expo Router's typed routes needed a `expo start --web` bounce** (per [[frontend_tooling_gotchas]])
before `tsc --noEmit` recognised the two new route files — same fix as before, just re-confirming it
still applies on this SDK/branch.
