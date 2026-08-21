# 16 — Playlist generation: Fast mode (UI)

**Command:** `/feature playlist-fast-mode-ui` · **Agent:** `frontend-engineer` · **Depends on:** 14, 15

## Goal
From a concert page, a user taps once and gets a playlist in their linked streaming account — with
live progress while it builds and an honest, readable account of anything that could not be matched.

## Context
Prompt 14 built the backend; prompt 15 designed the screens. This is where the product's core promise
becomes real for the first time.

The bar, restated because it is easy to lose in implementation: **a partial result is a success.**
Design and code must both treat "14 of 19 matched" as a good outcome presented plainly, never as an
error state with a red icon.

## Scope
- Generation trigger on the concert detail page, with provider selection when the user has linked more
  than one (reading `GET /api/config/providers` from prompt 11 — never a hardcoded provider list).
- Progress UI driven by the mechanism prompt 13 chose, showing per-song progress rather than an
  indeterminate spinner. Generation takes tens of seconds; the wait must feel accounted for.
- All four result variants from prompt 15 — fully, mostly, barely, and nothing matched.
- The per-song report, with plain-language reasons and no error codes.
- Every degraded state: band unknown · no songs recorded · setlist.fm budget exhausted · provider
  quota exhausted · token expired (with a re-link path) · provider disabled by the operator.
- The generated playlist rendered on the concert page, with the region prompt 19 will fill left
  visibly reserved.
- Retry, and delete-playlist, both with confirmation.
- Backgrounding: leaving the screen or the app mid-generation must not lose the job — it continues
  server-side and the result is there on return.
- Tests: each result variant, each degraded state, progress rendering, and retry.

## Out of scope
- Normal mode — prompt 17.
- Playback — prompt 19.
- Sharing the playlist — prompt 21.

## Acceptance criteria
- [ ] One action from the concert page produces a playlist in the linked account, on all three
      platforms.
- [ ] Progress is visible and per-song throughout; the UI never appears frozen.
- [ ] All four result variants render per prompt 15, and none of the partial ones reads as an error.
- [ ] Every miss is explained in plain language.
- [ ] Each degraded state renders its designed screen; none surfaces a raw error or an HTTP code.
- [ ] An expired provider token leads to a working re-link path, and the generation can then be
      retried.
- [ ] A disabled provider (toggled in `/admin`) shows the unavailable state, not a crash.
- [ ] Leaving the screen mid-generation and returning shows the completed result.
- [ ] Provider selection comes from the config endpoint, never from a hardcoded list.
- [ ] Tests green on all three platforms.

## Risks & open questions
- Polling versus a push mechanism behaves differently on Expo web and native, and differently again
  when the app is backgrounded on iOS. Implement whatever prompt 13 chose, and test backgrounding on a
  real device rather than a simulator.
- Resist adding a red error colour to partial results. The visual language should say "here's what we
  got" — the honesty is the feature.
- If generation regularly exceeds ~30 seconds, consider whether the user should be able to leave and
  be notified on completion. Note it; do not build push notifications here.
