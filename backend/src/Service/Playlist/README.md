# `Service/Playlist/`

Turns a queued generation request into a playlist, or an honest degraded result — the pipeline spec
13 designed (`docs/specs/2026-08-23-spike-playlist-pipeline.md`) and spec 14 implements
(`docs/specs/2026-08-23-playlist-fast-mode-backend.md`).

**`PlaylistPipeline::run()` is the single entry point**, used by both Fast mode (this feature) and
Normal mode (prompt 17) — the mode is read at exactly two guards inside `Stage/ReviewStage` and
`Stage/SetlistSelectionStage`. `App\MessageHandler\BuildPlaylistHandler` is the only caller: a lock,
a load, and a call.

**`JobStateMachine` is the only class permitted to assign `PlaylistGenerationJob::$state`** (D-159).
`App\Tests\Unit\Service\Playlist\JobStateMachineIsOnlyStateWriterTest` enforces that structurally.
Eleven states, twenty numbered transitions (spec 13 §1); an illegal edge raises `\LogicException` —
a bug, never a user-facing error.

**The seven stages, in order:** `PreflightStage` → `SetlistSelectionStage` → `MatchingStage` →
`ReviewStage` → `CreationStage` → `InsertionStage` → `ReportStage`. Match everything first, create
the provider playlist last (D-135) — `CreationStage`/`InsertionStage` sit after `MatchingStage` on
purpose, so a quota-exhausted provider, an empty setlist, or zero matched tracks never litter a
user's account. `ProviderRegistry::isAvailable()` is re-checked at every stage boundary (D-134/F-07).

**This directory never names a provider and never hardcodes a provider key literal.**
`App\Tests\Unit\Service\Playlist\PlaylistServiceIsProviderFreeTest` enforces that structurally, the
same technique `MatchingServiceIsProviderFreeTest` uses. A provider is reached only through
`App\Service\Streaming\StreamingProviderInterface` and `StreamingProviderLocator`, keyed by a
runtime string.

**Idempotency has three levels** (spec 14 §5): the partial unique index on live jobs (Level 1, in
`StartGenerationProcessor`), the `Playlist` creation marker — `creationAttemptedAt` committed before
`createPlaylist()`, `providerPlaylistId` after (Level 2, `CreationStage`) — and the insertion
watermark, `insertedThroughOrdinal`, advanced only after a confirmed provider call (Level 3,
`InsertionStage`).

`JobProgressWriter` writes one song's resolution and `songsProcessed++` in its own small
transaction. `GenerationEstimator` turns that into `estimatedSecondsRemaining`.
`SubstantialSetlistSelector` picks "most recent substantial" (D-132). `Naming/PlaylistNamer` derives
the playlist's name and description from the concert alone.

**Rule to remember:** playlist generation degrades, it does not fail (`CLAUDE.md`). Only three
routes ever reach `failed` (F-14, F-15, block-cycle exhaustion) — "some songs were missing" is never
one of them.
