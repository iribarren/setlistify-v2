# 12 — SPIKE: song matching

**Command:** `/spec spike-song-matching` · **Agent:** `backend-engineer` · **Depends on:** 09, 10

## Goal
A design document and a recommendation for turning a song name scrawled in a setlist.fm entry into a
specific, playable track in a streaming provider's catalog — including a defensible answer for when
it cannot be done confidently.

**This prompt produces a document, not an implementation.** Use `/spec`.

## Context
This is the hardest problem in Setlistify and the one most likely to determine whether the product
feels good or feels broken. Everything upstream and downstream is comparatively ordinary
CRUD; this is where the value is.

The inputs are messy in ways that are worth stating plainly:

- setlist.fm song names are crowd-entered: abbreviations, typos, alternate titles, translations,
  inconsistent punctuation, `(Acoustic)`, `(Extended Intro)`.
- Setlists contain **covers** (attributed to another band), **medleys** (several songs in one entry),
  **snippets/teases**, and non-songs (`Tape`, `Intro`, `Drum Solo`, `Encore Break`).
- Provider catalogs contain the studio version, several remasters, live albums, radio edits,
  compilations, regional variants — and on YouTube, an ocean of fan uploads and lyric videos.
- Region restrictions mean an available track for one user is unavailable for another.
- Some songs are simply not in the catalog at all.

Fast mode must pick one automatically. Normal mode must present a ranked, meaningful choice. Both
need the same underlying signal: a **confidence score** that is honest.

## Scope of the investigation
- **Normalization**: case, diacritics, punctuation, leading articles, featured-artist suffixes,
  parenthetical qualifiers. What to strip, what to keep, and what each choice costs.
- **Matching algorithm**: exact → normalized → fuzzy. Evaluate the available PHP options
  (`levenshtein`, `similar_text`, trigram similarity, PostgreSQL `pg_trgm`) and recommend one, with
  reasoning about cost per lookup — a 25-song setlist means dozens of comparisons per generation.
- **Confidence scoring**: define the signals (title similarity, artist match, duration proximity,
  album type, popularity, live/studio classification) and how they combine into one number. Define
  the thresholds: auto-accept, present-for-choice, reject.
- **Version preference**: is the studio version the right default? A setlist is a *live* performance —
  argue the case either way and recommend one, with a note on whether it should be user-configurable.
- **Special cases**: a decision for each of covers, medleys, snippets, non-song entries, and songs
  absent from the catalog.
- **Cross-provider differences**: Spotify's catalog is clean and well-identified; YouTube's is not.
  Does the same algorithm serve both, or does confidence need per-provider calibration? Prompt 18
  depends on this answer.
- **Evaluation**: propose a way to measure match quality — a hand-labelled fixture set of real
  setlists with known-correct tracks, and a pass/fail metric — so future changes can be shown to be
  improvements rather than merely different.
- **Caching**: song→track resolutions are reusable across users. What is cacheable, keyed how, and
  invalidated when?

## Out of scope
- Writing the matcher. This prompt recommends; prompt 14 implements.
- The job pipeline and the two modes — prompt 13.
- UI for reviewing matches — prompt 15.

## Acceptance criteria
- [ ] A written recommendation exists in `docs/specs/`, with the algorithm specified precisely enough
      to implement without further research.
- [ ] Confidence scoring is defined numerically, with justified auto-accept and reject thresholds.
- [ ] Every special case (cover, medley, snippet, non-song, absent) has a decided behaviour.
- [ ] The version-preference question is answered with reasoning, not deferred.
- [ ] A concrete evaluation method is proposed, with a fixture set sketched from **real** setlists.
- [ ] Per-provider differences are addressed, and the recommendation states whether prompt 18 needs
      separate calibration.
- [ ] Performance is estimated: expected matching time and provider-call count for a 25-song setlist.
- [ ] Caching strategy is specified.

## Risks & open questions
- The temptation is to over-engineer this. A simple approach with honest confidence reporting and a
  good "here's what we couldn't match" story may well beat a clever one that fails silently. Say so
  if that is the conclusion.
- Provider search endpoints are rate-limited and, on YouTube, quota-expensive (100 units per search
  against a 10,000/day budget). Matching strategy is constrained by that budget, not only by accuracy.
- Resist any impulse to reach for Spotify's audio-features endpoints; access has been restricted for
  new apps, and the design must not assume them.
