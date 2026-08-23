# `Service/Streaming/`

> `StreamingProviderInterface` + one directory per adapter.

Shipped in `docs/specs/2026-08-22-streaming-port-and-account-linking.md` (D-71–D-88). The port
(`StreamingProviderInterface.php`), the provider-agnostic value objects (`Model/`), the error
taxonomy (`Exception/`), the tagged-service locator (`StreamingProviderLocator.php`) and the
link/refresh lifecycle (`Link/`) all live directly under this directory — none of them may reference
a provider.

**Rule:** only `Service/Streaming/<Provider>/` knows a provider exists. Everything upstream sees the
interface (`docs/architecture.md` §4, `CLAUDE.md` — "the streaming port is the only way to reach a
provider"). No `Spotify`, `YouTube` or `Apple` symbol may appear outside its own adapter directory —
enforced structurally by `App\Tests\Unit\Service\Streaming\SpotifySymbolIsolationTest` (D-82,
AC-9.4), not just this convention.

`Spotify/` is the first (reference) adapter. Adding a second provider means a new sibling directory,
one entry in `App\Service\Streaming\Link\LinkFlowService`'s redirect-URI map (`config/services.yaml`),
and a service tagged `app.streaming_provider` — nothing else in the codebase changes
(`TestDoubleProviderIsDiscoverableTest` proves this for a test-double adapter, AC-9.5).

**Confidence scoring has left the adapter (D-147, redeeming D-83's "provisional" label).**
`SpotifyTrackMapper` no longer scores a candidate — it maps a search response into
`TrackCandidate[]`, including the generic, provider-agnostic signal fields
(`artistAuthority`/`albumType`/`popularity`/`isrc`/`providerRank`, D-119) that
`App\Service\Matching\MatchConfidence` scores instead. `TrackCandidate::$confidence` remains, but
purely as a provider-result-rank-derived ordering hint that scorer never reads. `SpotifyQueryBuilder`
holds query construction (including `market`), so an adapter's three jobs relative to matching are
now: build the query, map the response, extract signals — never score.
