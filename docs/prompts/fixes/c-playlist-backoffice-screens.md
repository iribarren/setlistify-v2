# C — Playlist generation: backoffice screens

**Branch:** prompt 14's branch (`feature/playlist-fast-mode-backend`), or a follow-up off `master`
if that has merged · **Priority:** Medium

Unfinished **prompt 14** scope. `backend/src/Controller/Admin/` has no playlist controller at all.
Prompt 14 calls generation time and match quality *"the two numbers that matter — measure both from
the first day and put them in the backoffice"*; the columns exist, nothing renders them.

```
Continue prompt 14 on feature/playlist-fast-mode-backend (or a follow-up branch — check).

The backoffice half of prompt 14 was never built. `backend/src/Controller/Admin/` has no playlist
controller. Prompt 14 says generation time and match quality "are the two numbers that matter —
measure both from the first day and put them in the backoffice", and spec 13 §metrics specifies
exactly what to show.

Build:
1. A read-only `PlaylistGenerationJobCrudController` — states, failures, blocked reasons, and
   per-song outcomes. No write actions at all.
2. A read-only playlists CRUD.
3. The "Playlist generation (last 7 days)" dashboard panel: p50/p95 duration, mean match rate,
   blocked-reason breakdown, and the five most-frequently-unmatched (artist, title) pairs.
   Investigate-thresholds from spec 13: p95 > 90s, match rate < 0.75, blocked share > 10%.

Follow the existing controllers' conventions (`AbstractAdminCrudController`, the audited/2FA-gated
admin). Read Doctrine directly across owners — that is the admin's separate channel (D-47).
`ConcertOwnerExtension` is NOT touched and NOT made role-aware.

The metrics columns already exist on the entities (`durationMs`, `stageTimings`, the outcome
counters, `meanConfidence`). Use them; do not recompute from logs.

Update `docs/architecture.md` for the new backoffice screens. Add tests. Report real test numbers.
```
