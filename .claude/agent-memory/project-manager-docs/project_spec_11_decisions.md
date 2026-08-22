---
name: spec-11-provider-config-decisions
description: Decisions D-89..D-105 proposed by the 2026-08-22 backoffice-provider-configuration spec — registry as sole read path, fail-open cache, independent enabled/playbackMode axes, deferred client work
metadata:
  type: project
---

`docs/specs/2026-08-22-backoffice-provider-configuration.md` (backlog prompt 11) proposes **D-89
through D-105**. Status as written: **draft, awaiting user approval**.

- **D-89** — `ProviderRegistry implements ProviderAvailability`; `StaticProviderAvailability` deleted
  in the same commit (two implementations of a kill switch is one too many).
- **D-90** — `StreamingProviderLocator` stays availability-unaware; "no adapter" (404) and "turned
  off" (503) must stay distinguishable.
- **D-91** — **honest finding**: prompt 10's D-86 seam shipped with *zero consumers*
  (`LinkFlowService` still calls the locator directly), so prompt 11 owns both the impl swap and the
  call-site wiring. "Changes no caller" was not free.
- **D-92** — invalidate the Redis snapshot after commit; TTL is a backstop, never correctness.
- **D-93** — registry returns immutable `ProviderConfig` VOs, never managed entities.
- **D-94** — disabled provider = typed 503; unknown key stays 404.
- **D-95** — an OAuth flow in flight when a provider is disabled **completes and persists** (else a
  live third-party grant exists with no local record to unlink).
- **D-96** — `StreamingAccountOutput` gains no availability field; client joins on `key`.
- **D-97** — `enabled` and `playbackMode` are independent axes; enabled+off is the Non-Streaming SDA
  posture the whole feature exists to make reachable.
- **D-98** — public endpoint sends `Cache-Control: no-store`; an HTTP cache in front of a kill switch
  is its failure mode.
- **D-99** — disabled providers still appear (`enabled: false`); adapter-less rows never appear. Deny
  by default.
- **D-100** — disabling the default clears it, never auto-promotes (silent change of destination
  service during an incident).
- **D-101** — backend-only; client rendering deferred to prompts 16/19. Accepted gap: until then a
  disabled provider yields a typed 503, not designed copy.
- **D-102** — migration seeds `spotify` enabled/embed/default and `youtube` disabled/off, so prompt
  18 is a flag flip not a migration. No `apple` row.
- **D-103** — `notes` is admin-only, never in any API response, digested in the audit log.
- **D-104** — audit values recorded literally (not D-43-digested): flags describe no user, and the
  log must answer "what was playbackMode the day monetization shipped".
- **D-105** — registry **fails open to Postgres** when Redis is down — deliberately unlike
  `RateLimiterGuard`/`SetlistFmBudget`, which fail closed. Failing closed here would cause the outage
  the feature exists to mitigate.

**Why:** this is ~400 lines of code guarding three unrelated things — YouTube's exhaustible 10k
units/day, Spotify's Streaming vs Non-Streaming SDA classification (embed vs deeplink decides whether
monetization is permitted at all), and keeping prompt 23's monetization choice reversible.

**How to apply:** Highest D-number after this spec is **D-105** — continue from D-106. One new env
var proposed: `PROVIDER_SETTINGS_CACHE_TTL` (default 300). Four open questions left for the user
(persist-on-disabled-callback, seeded Spotify playbackMode, zero-defaults, deferred client work).
Prompt 11's AC-4.1 enumerates the seven graceful-disable call sites — reuse that list when prompts
14/17/19 land. See [[spec-10-streaming-port-decisions]], [[spec-08-backoffice-decisions]] and
[[backlog-prompt-to-spec-flow]].
