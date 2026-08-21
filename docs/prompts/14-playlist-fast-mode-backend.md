# 14 — Playlist generation: Fast mode (backend)

**Command:** `/feature playlist-fast-mode-backend` · **Agent:** `backend-engineer` · **Depends on:** 12, 13

## Goal
A user points at a tracked concert, asks for a playlist, and gets one — no further input — built from
the band's most recent setlist with songs matched automatically, plus an honest report of anything
that could not be matched.

## Context
The first implementation of the designs from prompts 12 (matching) and 13 (pipeline). **Follow those
specs**; if implementation reveals they were wrong, update them in the same branch rather than
diverging silently.

The defining quality bar for this feature: **it degrades, it does not fail.** A playlist with 14 of 19
songs and a clear explanation is a success. An error page because three songs were missing is a bug.

## Scope
- `Playlist` and `PlaylistTrack` entities per `docs/architecture.md` §10 — every song in the source
  setlist gets a row, including unmatched ones, carrying its `outcome`.
- `PlaylistGenerationJob` entity and state machine, exactly as specified by prompt 13.
- The matching implementation from prompt 12: `SongNormalizer`, `TrackMatcher`, `MatchConfidence`,
  with the recommended thresholds and the caching strategy.
- `BuildPlaylistHandler` (Messenger) executing the pipeline: select the most recent setlist with
  songs → normalize → match → create the provider playlist → add tracks in setlist order → write the
  report.
- API: start a generation, poll job status with per-song progress, fetch the finished playlist and its
  report, delete a playlist.
- Every failure mode from prompt 13's taxonomy handled as specified — with particular care for
  setlist.fm budget exhaustion, provider quota exhaustion mid-run, token expiry mid-run, and a
  provider disabled mid-run via prompt 11.
- Idempotency: retrying never produces a second provider playlist or duplicate tracks.
- Provider selection through `ProviderRegistry` (prompt 11) — never a hardcoded provider.
- Backoffice additions: browse generation jobs, their states, failures and per-song outcomes.
- Tests: the happy path, each significant failure mode, ordering preservation, partial success,
  idempotent retry, and the evaluation fixture set from prompt 12 as a regression guard on match
  quality.

## Out of scope
- Any UI — prompt 16.
- Interactive setlist or version selection — prompt 17.
- Multi-provider generation — prompt 18 adds the second adapter.

## Acceptance criteria
- [ ] A concert with a well-covered band produces a complete playlist in the user's Spotify account,
      in setlist order.
- [ ] A band with **no** setlist.fm data returns a clear, non-error "no setlists available" outcome.
- [ ] A band whose setlist contains unmatched songs still produces a playlist, plus a report naming
      exactly what was missed and why.
- [ ] Setlist order is preserved, including when tracks are missing from the middle.
- [ ] Covers, medleys and non-song entries behave as prompt 12 specified.
- [ ] Retrying a failed job does not create a duplicate playlist or duplicate tracks.
- [ ] Quota exhaustion (setlist.fm or provider) mid-run leaves the job in a defined, recoverable state.
- [ ] A provider disabled mid-run fails cleanly with a typed error, per prompt 11.
- [ ] Match quality against the prompt-12 fixture set meets the agreed threshold, and the test fails if
      it regresses.
- [ ] Generation never blocks an HTTP request; progress is observable throughout.

## Risks & open questions
- **This is where the product succeeds or disappoints.** Generation time and match quality are the two
  numbers that matter — measure both from the first day and put them in the backoffice.
- "Most recent setlist with songs" is not always the best setlist. A band's latest entry may be a
  three-song festival slot. Consider whether "most recent *substantial*" is the better default, and
  record the decision.
- Multi-band concerts: implement whatever prompt 13 decided. Do not improvise it here.
- Guard the fixture-based quality test carefully — it is the only thing standing between a matching
  tweak and a silent regression across every future generation.
