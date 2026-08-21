# 17 — Playlist generation: Normal mode

**Command:** `/feature playlist-normal-mode` · **Agent:** `backend-engineer` + `frontend-engineer` · **Depends on:** 14, 15, 16

## Goal
The interactive generation path: the user picks **which** show's setlist to use, then picks **which
version** of each ambiguous song, and gets a playlist that matches what they actually wanted.

## Context
Fast mode guesses. Normal mode asks. Both run the same pipeline from prompt 13 — they differ only in
who resolves ambiguity, so this prompt should be adding suspend/resume points to an existing pipeline,
**not** building a second one. If it starts to feel like a parallel implementation, stop and revisit
prompt 13's design.

The dominant design risk was flagged in prompt 15: asking a user to choose a version for all 25 songs
of a setlist is exhausting and will be abandoned. Prompt 15's recommendation on pre-resolving
confident matches should be followed — surface only the genuinely ambiguous ones, with the rest
reviewable.

## Scope

**Backend**
- Pipeline suspension and resumption at the two decision points, per prompt 13: setlist selection and
  version selection.
- Persist partial state so an abandoned session resumes rather than restarts, with the expiry policy
  prompt 13 specified.
- Endpoints: list a band's candidate setlists (date, venue, tour, song count, from the prompt-09
  cache), submit a setlist choice, fetch ranked `TrackCandidate`s for the songs needing a decision,
  submit version choices, confirm and build.
- Handle staleness: if the underlying setlist or a candidate track changed while the session was
  suspended, behave as prompt 13 specified rather than failing.
- Remember a user's version preferences where it is safe to do so, so repeat generations for the same
  band ask less each time.

**Frontend**
- Setlist selection: recent shows with enough context (date, venue, song count) to choose
  meaningfully, and clear handling when a band has only one, or none.
- Version selection: ranked candidates per ambiguous song, with the confidence signal made legible
  without exposing a raw number. Confident matches pre-resolved and reviewable, per prompt 15.
- Progress through the steps, ability to go back, and a visible resume path for a suspended session.
- Confirmation, then the same result and report screens as Fast mode.

**Both**
- Tests: full interactive flow, suspend and resume, expiry, staleness, and a band with a single
  setlist or none.

## Out of scope
- Fast mode changes beyond what sharing the pipeline requires.
- Playback — prompt 19.
- Editing a playlist after creation.

## Acceptance criteria
- [ ] A user completes setlist selection → version selection → confirmation and gets the playlist they
      chose, on all three platforms.
- [ ] **Both modes demonstrably share one pipeline** — asserted by test, not by inspection.
- [ ] A suspended session resumes correctly after an app restart and after days elapsed.
- [ ] An expired session is handled per prompt 13, with a clear path forward rather than a dead end.
- [ ] Only genuinely ambiguous songs require a decision; confident matches are pre-resolved and can be
      reviewed.
- [ ] A 25-song setlist can be completed without exhausting the user — measured by the number of
      decisions actually required.
- [ ] Setlist selection shows enough context to choose meaningfully.
- [ ] A band with one setlist, or none, is handled cleanly.
- [ ] Going back a step does not lose earlier choices.
- [ ] Stale underlying data behaves as specified rather than erroring.

## Risks & open questions
- **The decision-count problem is the make-or-break issue here.** Measure it: if a typical setlist
  still demands more than a handful of decisions, the pre-resolution thresholds from prompt 12 need
  revisiting, and that is a legitimate outcome of this work.
- Suspended sessions hold provider search results that go stale. Prompt 13 decided the policy — follow
  it, and make expiry visible to the user rather than silent.
- Version selection consumes provider search quota per song. On YouTube (100 units per search against
  10,000/day) an interactive session is genuinely expensive — check the arithmetic before prompt 18
  makes it real.
