# 15 — Playlist flow design

**Command:** `/design` · **Agent:** design canvas · **Depends on:** 02, 06, 13

## Goal
Designed screens for both playlist generation modes — including, crucially, the many partial and
degraded outcomes that are the normal result rather than the exception.

## Context
Prompt 13 enumerated the failure taxonomy; prompt 14 implements it. This prompt decides what all of
that **looks like**, before any of it is built into the client.

The design problem here is unusual and worth naming: in most products, degraded states are edge cases
to be handled tastefully. In Setlistify, "we matched 14 of 19 songs" is a **typical Tuesday**. If the
partial result looks like an error, the product will feel broken while working exactly as designed.
The report screen deserves as much design attention as the success screen — arguably more.

## Scope
Artboards, phone and desktop, for:
- **Mode selection** — how a user chooses Fast vs Normal, and how the difference is explained without
  jargon. Most people will not read an explanation; the choice must be self-evident.
- **Fast mode**: trigger → progress (a 25-song generation is not instant; the wait needs designing) →
  result.
- **The result screen**, in several variants: fully matched · mostly matched · barely matched ·
  nothing matched. Each must read as an honest outcome, not a failure.
- **The "what we couldn't match" report** — per-song, with the reason in plain language ("not on
  Spotify", "only a live version was available", "this was a drum solo"). This is the signature
  screen of the product's honesty.
- **Normal mode**, step by step: choose a setlist from the band's recent shows (with date, venue and
  song count so the choice is informed) → per-song version selection with ranked candidates → confirm.
- **Suspend and resume** — Normal mode may be abandoned mid-flow and returned to days later. Design
  the re-entry.
- **Every degraded state**: band unknown to setlist.fm · band known but no songs · setlist.fm budget
  exhausted · provider quota exhausted · token expired, re-link needed · provider disabled by the
  operator (prompt 11).
- **Playlist display on the concert page** — the generated playlist as it appears once done, with
  space reserved for the playback surface prompt 19 adds.

## Out of scope
- Implementation — prompts 16 and 17.
- The playback surface itself — prompt 19.
- Notes, reviews and sharing — prompts 20 and 21.

## Acceptance criteria
- [ ] Both modes are designed end to end at phone and desktop width.
- [ ] **All four result variants** (full/mostly/barely/nothing matched) are drawn and visually
      distinct without any of them reading as an error.
- [ ] The per-song report explains each miss in plain language, never in error codes.
- [ ] Normal mode's version-selection step handles a 25-song setlist without becoming exhausting —
      this is the interaction most at risk of tedium.
- [ ] Setlist selection shows enough context (date, venue, song count) to choose meaningfully.
- [ ] Suspend/resume re-entry is designed.
- [ ] Every degraded state listed above has a screen, each distinguishable from the others.
- [ ] Progress states are designed for a wait measured in tens of seconds, not milliseconds.

## Risks & open questions
- Per-song version selection across 25 songs is the single biggest UX risk in the product. Consider
  designing it so only genuinely ambiguous songs need a decision, with confident matches pre-resolved
  and reviewable — that turns 25 decisions into three.
- Mode selection may not need to be an explicit choice at all. Consider whether Fast is simply the
  default with a "let me choose" affordance on the result. Explore it; recommend one.
- The tone of the report matters more than its layout. "Not available on Spotify" is honest;
  "Failed to match track" sounds like the app broke.
