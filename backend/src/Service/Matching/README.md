# `Service/Matching/`

Turns one setlist entry into a provider track, or an honest reason it has none (spec 12,
`docs/specs/2026-08-22-spike-song-matching.md`).

**`TrackMatcher` is the only public entry point**, mirroring `App\Service\Setlist\SetlistGateway`'s
single-door shape (D-58): a rule is only as strong as its weakest caller. It runs the Tier 0–7
cascade — non-song/tape pre-filter, medley split, resolution cache lookup, one
`StreamingProviderInterface::searchTrack()` call, exact/normalized/fuzzy title comparison, artist
comparison, confidence scoring, and persisting the resolution either way.

**This directory never names a provider and never hardcodes a provider key literal.**
`App\Tests\Unit\Service\Matching\MatchingServiceIsProviderFreeTest` enforces that structurally, the
same technique `SpotifySymbolIsolationTest` uses for `Service/Streaming/Spotify/`. Per-provider
calibration is `config/matching/profiles.yaml`, keyed by `StreamingProviderInterface::key()` — a
runtime string, resolved through `MatchProfileRegistry`, never a PHP branch (D-118). Adding a second
provider's calibration (prompt 18) must never touch a class in this directory.

`SongNormalizer` (N0–N8), `Similarity\TitleSimilarity`/`ArtistSimilarity`, `MatchConfidence`,
`NonSongClassifier` and `MedleySplitter` are `TrackMatcher`'s pure, provider-agnostic building
blocks. `Cache\TrackResolutionStore` is the Redis-over-PostgreSQL resolution cache (D-121) —
reusable across every user, deliberately excluding `market`/region from its key, because which
recording a title resolves to does not depend on where the asker stands.

**Rule to remember:** playlist generation degrades, it does not fail (`CLAUDE.md`). Missing
setlists, unmatched songs and ambiguous versions are the normal case — every song gets an honest
outcome (`matched` / `matched_low_confidence` / `not_found` / `skipped`), never a silent drop.
