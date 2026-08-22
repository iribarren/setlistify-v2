# `Service/Provider/`

> Filled in `docs/specs/2026-08-22-backoffice-provider-configuration.md` (D-89–D-105). The seam
> `ProviderAvailability` shipped empty in prompt 10 (D-86) now has a real implementation and real
> callers.

## What's here

- `ProviderAvailability` — the interface, unchanged since prompt 10.
- `ProviderRegistry` — the only read path (US-10). Implements `ProviderAvailability`. Redis-cached
  (one key, the whole snapshot), invalidated explicitly on every write, falls open to a direct
  database read if Redis is unavailable (D-105) rather than disabling every provider over a Redis
  blip.
- `ProviderSettingWriter` — the only write path (AC-8.1). Every admin edit goes through this: it
  enforces the default-uniqueness rules (AC-7.1–AC-7.4), audits one entry per changed field
  (`App\Service\Admin\AuditLogger`, D-104), and invalidates `ProviderRegistry`'s cache after its
  transaction commits (D-92).
- `ProviderConfig` — the immutable snapshot value object `ProviderRegistry` hands out (D-93). Never
  a managed `App\Entity\ProviderSetting`.
- `PlaybackMode` — backed enum: `embed` | `deeplink` | `off`.
- `ProviderDisabledException` — the typed `503` for a provider that exists but is currently disabled
  (D-94). Implements API Platform's `ProblemExceptionInterface` directly, so a processor/provider
  can just let it propagate.
- `ProviderSettingValidationException` — AC-7.3: rejects explicitly promoting a disabled provider to
  default.

`StaticProviderAvailability` — the prompt 10 placeholder that answered "every registered adapter is
available" — is **deleted** (D-89). Two registered implementations of a runtime kill switch is one
too many; `ProviderRegistry` is now the only thing wired to the `ProviderAvailability` alias
(`config/services.yaml`).

## Who else may reference `ProviderSetting`/`ProviderSettingRepository`

Only `ProviderRegistry` and `ProviderSettingWriter` — enforced by `App\Tests\Unit\Service\Provider\
ProviderSettingIsOnlyDoorTest` (AC-10.1), the same static-scan shape as `App\Service\Setlist\
SetlistGatewayIsOnlyDoorTest`. The one disclosed exception is `App\Controller\Admin\
ProviderSettingCrudController`, which must name the entity for EasyAdmin's `getEntityFqcn()` —
EasyAdmin's CRUD machinery has no other way to bind a screen to an entity. That controller never
persists the entity itself; its `updateEntity()` override routes every write through
`ProviderSettingWriter`.

## Wired call sites (US-4)

As of this branch, four production call sites ask `ProviderAvailability` before doing anything a
disabled provider shouldn't be allowed to do:

1. `App\Service\Streaming\Link\LinkFlowService::start()` — refuses before touching the rate limiter
   or the locator (503).
2. `App\State\Processor\StreamingLinkStartProcessor` — lets `ProviderDisabledException` propagate
   (it maps itself to 503); `UnknownProviderException` still maps to 404.
3. `App\Service\Streaming\Link\StreamingTokenManager::refreshAndPersist()` — refuses a refresh
   without changing the account's status (a disabled provider is an operator state, not a broken
   grant, D-80).
4. `App\State\Provider\ProviderConfigProvider` (`GET /api/config/providers`) — reads the registry
   directly; a disabled provider still appears, with `enabled: false` (D-99).

Three more call sites were reviewed and deliberately left unchanged, because availability doesn't
apply to them: the OAuth callback in flight (`StreamingCallbackController` — D-95, must complete and
persist even if the provider is disabled mid-flow), the accounts list and unlink processor (must
work regardless of provider state — AC-4.4, AC-4.5), and the backoffice's own `StreamingAccountCrudController`
(the admin channel is not gated by user-facing availability, D-84).

## Obligation for later prompts (AC-4.10)

**Prompts 14/17 (playlist generation) and 19 (playback surface) must read `ProviderRegistry` before
selecting a provider or rendering a playback surface — never `StreamingProviderLocator` alone.**
The locator only answers "does an adapter exist?" (D-72); it stays deliberately availability-unaware
(D-90). Choosing a provider, or deciding whether/how to play a generated playlist, is an availability
question, and the registry is the only place that answers it correctly at request time.
