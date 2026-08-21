# `Service/Provider/`

> `ProviderRegistry`, `ProviderSettingWriter`.

Out of scope for this feature (prompt 08 onward).

**Rule to remember when this fills in:** provider state is read at runtime, not baked in
(`CLAUDE.md`). Anything that offers a provider to a user, or decides playback, reads
`ProviderRegistry` — so a provider can be disabled mid-incident without a deploy. Credentials never
live here or in `ProviderSetting` — only behaviour flags (`enabled`, `playbackMode`, `isDefault`).
