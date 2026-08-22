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
