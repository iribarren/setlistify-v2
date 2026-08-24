---
name: spec-result-state-gaps-decisions
description: D-182..D-187 — the fix spec closing spec 16's AC-6.6 and AC-4.4 gaps (NoSetlistCause enum, sourceSetlists on PlaylistOutput)
metadata:
  type: project
---

`docs/specs/2026-08-24-playlist-result-state-gaps.md` (draft, review requested 2026-08-24) is a
**fix** spec, not a backlog prompt — it closes two gaps prompt 16 shipped with.

- **D-182** — the two gaps are bundled in one spec/branch (`bugfix/playlist-result-state-gaps`
  suggested) because they touch the same two DTOs, the same two mappers and one
  `frontend/api/schema.d.ts` regeneration.
- **D-183** — new `App\Service\Playlist\Model\NoSetlistCause` (`band_unknown`, `band_ambiguous`,
  `no_setlist_for_show`, `identity_unavailable`), written as a `cause` param on the existing
  `NO_SETLIST_FOR_BAND` report entry in `SetlistSelectionStage`, read off
  `Band::$setlistfmResolutionState`.
- **D-184** — `noSetlistCause` is **derived in the output mappers** by folding the report summary,
  not persisted on the job. No migration.
- **D-185** — `PlaylistOutput.sourceSetlists` is derived from the track rows'
  `PlaylistTrack::$sourceSetlistfmId`, not from `PlaylistGenerationJob::$selectedSetlists`.
- **D-186** — setlist.fm URLs are built backend-side by `App\Service\Setlist\SetlistFmUrl` only.
- **D-187** — frontend wiring stays thin: one branch in `derivePlaylistView()`, one button in
  `ResultCard.tsx`. No new view kind, no new artboard.

**Why:** everything exposed was already persisted; the gap was in the mappers, so the whole fix is
migration-free — which is what justifies bundling it as a bugfix rather than a feature.

**How to apply:** the blocking open question is **Q-2** — whether `https://www.setlist.fm/setlist/{id}.html`
actually resolves. If it does not, `Setlist` needs a nullable `url` column populated in
`SetlistNormalizer::hydrateOne()` and the fix stops being migration-free.
See [[spec-house-style]] and [[spec-16-fast-mode-ui-decisions]].
