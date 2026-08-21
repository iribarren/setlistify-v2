# `Service/Streaming/`

> `StreamingProviderInterface` + one directory per adapter.

Out of scope for this feature (`StreamingProviderInterface` is not written here — prompts 10–11,
18).

**Rule:** only `Service/Streaming/<Provider>/` knows a provider exists. Everything upstream sees
the interface (`docs/architecture.md` §3–§4, `CLAUDE.md` — "the streaming port is the only way to
reach a provider"). No `Spotify`, `YouTube` or `Apple` symbol may appear outside its own adapter
directory here.
