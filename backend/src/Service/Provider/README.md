# `Service/Provider/`

> `ProviderAvailability` today; `ProviderRegistry`, `ProviderSettingWriter` in prompt 11.

`ProviderAvailability` (interface) + `StaticProviderAvailability` (its constant implementation) shipped
in `docs/specs/2026-08-22-streaming-port-and-account-linking.md` (D-86) — call sites that need to know
whether a provider is offered to a user ask this seam rather than assuming Spotify (or any adapter) is
always there. Today's implementation answers "every adapter registered with `StreamingProviderLocator`
is available"; it deliberately does not build `ProviderSetting` or a real enabled/disabled flag.

**Rule to remember when this fills in:** provider state is read at runtime, not baked in
(`CLAUDE.md`). Anything that offers a provider to a user, or decides playback, reads
`ProviderRegistry` — so a provider can be disabled mid-incident without a deploy. Credentials never
live here or in `ProviderSetting` — only behaviour flags (`enabled`, `playbackMode`, `isDefault`).
Prompt 11 replaces `StaticProviderAvailability` with the real `ProviderSetting`-backed
implementation and changes no caller — that's the property this seam exists to buy.
