# `Service/Matching/`

> `SongNormalizer`, `TrackMatcher`, `MatchConfidence`.

Out of scope for this feature (playlist generation pipeline, `docs/architecture.md` §8).

**Rule to remember when this fills in:** playlist generation degrades, it does not fail
(`CLAUDE.md`). Missing setlists, unmatched songs and ambiguous versions are the normal case — every
song gets an honest outcome (matched / low-confidence / not found / skipped), never a silent drop.
