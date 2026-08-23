# B — Playlist generation: the missing failure-mode tests

**Branch:** prompt 14's branch (`feature/playlist-fast-mode-backend`), or a follow-up off `master`
if that has merged · **Priority:** High

Unfinished **prompt 14** scope. Only 3 of ~50 planned tests exist, and the missing ones cover the
paths where a bug is both most likely and most expensive: idempotent retry, and exhaustion partway
through a run. Several are named acceptance criteria in `docs/prompts/14-playlist-fast-mode-backend.md`.

```
Continue prompt 14 on feature/playlist-fast-mode-backend (or a follow-up branch off master if
that one is already merged — check first and say which you used).

Read `docs/specs/2026-08-23-playlist-fast-mode-backend.md` §8 and
`docs/specs/2026-08-23-spike-playlist-pipeline.md` (F-01…F-16) in full.

Only 3 of ~50 planned tests exist: happy path, no_source_material, and provider-disabled-at-
preflight (`backend/tests/Playlist/`). Write the missing ones. These are prompt 14 acceptance
criteria, not nice-to-haves, and they cover the paths most likely to be wrong:

- Idempotent retry: retrying a failed job creates NO second provider playlist and NO duplicate
  tracks. Exercise the creation marker (`creationAttemptedAt` before `createPlaylist()`) and the
  insertion watermark (`insertedThroughOrdinal`) directly, including a crash between the two.
- Quota exhaustion mid-run (provider AND setlist.fm) → `blocked` with the right `blockedReason`
  and `resumableAfter`, work already done preserved.
- OAuth token expiry mid-run → `blocked/needs_reauth`.
- Provider disabled mid-run at a LATER stage boundary, not just preflight — the spec requires the
  re-check at every boundary; prove it fires at each one.
- Region-restricted track at insert time → per-track outcome, job still completes.
- Setlist order preserved when tracks are missing from the MIDDLE of the list.
- Multi-band stage ordering: `billingOrder DESC`, headliner last, plus the 4-band/60-song caps
  cutting from the lowest-billed end with the right report codes.
- The 8 functional API tests: status codes, ownership (404 never 403), ETag/304, state-derived
  Retry-After, and that a `blocked` job returns HTTP 200.

Fake the provider with the existing tests/-resident tagged adapter and setlist.fm at the data
layer, so neither single-door static test weakens. Do not touch ConcertOwnerExtension.

Run: docker compose exec -T backend php -d memory_limit=512M vendor/bin/phpunit
Baseline is 397 passing — report real numbers, and if a test exposes a production bug, FIX the
production code and say so rather than adjusting the test to pass.
```
