# 11 — Backoffice provider configuration

**Command:** `/feature backoffice-provider-configuration` · **Agent:** `backend-engineer` · **Depends on:** 08, 10

## Goal
The owner can turn a streaming provider on or off, and change how playlists are played back, from the
backoffice — taking effect immediately, for every client, with no deploy and no app-store release.

## Context
**Read `docs/architecture.md` §6 and §7, and `docs/external-apis.md` §Spotify and §YouTube.**

This is small in code and disproportionately important in consequence. It exists for three distinct
reasons:

1. **Operational.** YouTube's 10,000 units/day quota can be exhausted mid-afternoon. When it is, the
   provider needs disabling in seconds, not in a deploy cycle.
2. **Legal.** Spotify's policy distinguishes a *Streaming SDA* (plays Spotify audio — no commercial
   use permitted at all) from a *Non-Streaming SDA* (creates playlists, hands off to Spotify —
   limited commercial uses permitted). Setlistify's iframe embed plausibly makes it the former.
   Harmless while unmonetized; live the moment revenue is switched on. `playbackMode = deeplink`
   converts the app to a Non-Streaming SDA **at runtime**.
3. **Strategic.** It keeps the monetization decision (prompt 23) reversible instead of baked into a
   release.

**The hard boundary:** this configures *behaviour*. Client IDs and secrets stay in the secret store.
No secret may be stored in, or rendered by, this feature — see `docs/env-vars.md`.

## Scope
- `ProviderSetting` entity, one row per provider, per `docs/architecture.md` §6: `provider` (unique),
  `enabled`, `playbackMode` (`embed` | `deeplink` | `off`), `isDefault`, `notes`, timestamps.
- A migration seeding rows for `spotify` and `youtube` with safe defaults.
- `ProviderRegistry`: the **single** read path for these flags, Redis-cached with explicit
  invalidation on write. Nothing reads `ProviderSetting` directly.
- Refactor prompt 10's consumers to go through the registry: listing linkable providers, and
  selecting a provider for any operation.
- **Graceful disable.** Disabling a provider must not break anything that already exists: linked
  accounts persist, generated playlists persist, and the client shows a "temporarily unavailable"
  state. No 500s, no data loss, no silent failure. Attempting to use a disabled provider returns a
  clear, typed error.
- Exactly one provider may be `isDefault`; enforce it.
- `GET /api/config/providers` — **public, unauthenticated, cached**: returns only non-sensitive flags
  (key, display name, enabled, playbackMode, isDefault) so the client renders the right affordances
  at startup. This endpoint must be incapable of leaking a credential.
- EasyAdmin screen extending prompt 08: edit the flags, with `playbackMode` carrying **inline
  explanatory text about the SDA implications** — the operator changing it must understand what it
  means without reading this file.
- Every change writes an `AuditLogEntry` (prompt 08). Provider-config changes matter most: they alter
  the app's legal classification.
- Tests: registry caching and invalidation, graceful disable across every consumer, single-default
  enforcement, public endpoint exposes no secret, audit entries written.

## Out of scope
- Provider credentials in any form — they stay in the secret store, permanently.
- Per-user provider overrides. This is global configuration.
- Choosing a monetization model — prompt 23.
- Feature flags generally. This is provider configuration, not a flag framework.

## Acceptance criteria
- [ ] Toggling `enabled` in `/admin` takes effect on the next client request, with no restart.
- [ ] **Disabling a provider degrades gracefully**: existing links and playlists survive, the client
      shows an unavailable state, nothing errors. Verified by test across every consumer.
- [ ] Changing `playbackMode` changes what the client renders, with no rebuild.
- [ ] `GET /api/config/providers` returns only non-sensitive fields — asserted by a test that fails if
      any new field is added without review.
- [ ] `ProviderRegistry` is the only read path; a test asserts nothing else queries `ProviderSetting`.
- [ ] Cache invalidation on write is immediate and verified.
- [ ] Exactly one provider can be default.
- [ ] Every change produces an audit entry with before and after values.
- [ ] **No secret is stored in or rendered by this feature** — verified by test.
- [ ] The admin screen explains the SDA consequence of `playbackMode` in plain language.

## Risks & open questions
- Caching a value whose whole purpose is fast change is a real tension. Invalidate explicitly on
  write; do not rely on TTL expiry for correctness.
- "Graceful" needs defining per consumer, and it is easy to miss one. Enumerate every call site during
  the spec, and test each — a missed one only shows up during an incident, which is exactly the
  moment this feature is meant to help.
- The public config endpoint is a permanent leak risk: it is unauthenticated and will accumulate
  fields over time. The test asserting its exact shape is the guard, and it must be strict.
- Record the current `playbackMode` values in the monetization spec when prompt 23 runs. They are an
  input to that decision, not an implementation detail.
