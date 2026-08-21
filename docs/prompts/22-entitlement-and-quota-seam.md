# 22 — Entitlements and usage quotas

**Command:** `/feature entitlement-and-quota-seam` · **Agent:** `backend-engineer` · **Depends on:** 09, 11, 14, 18

## Goal
Per-user usage limits that protect the application's hard external budgets — and, as a side effect,
provide the seam a paid tier would plug into if one is ever introduced.

## Context
**This is infrastructure, not monetization.** It earns its place regardless of whether Setlistify is
ever sold, because the external budgets it protects are real and small:

- **setlist.fm: 1,440 requests/day for the entire application** (`docs/external-apis.md` §setlist.fm).
- **YouTube: 10,000 units/day**, roughly four full generations, at 100 units per search
  (§YouTube).

Without per-user limits, one enthusiastic user with a festival lineup can exhaust the day's budget for
everyone. That is an availability bug, and it exists today.

Building it as an entitlement system rather than as hardcoded limits costs almost nothing extra and
means prompt 23 can introduce tiers by adding rows, not by rewriting enforcement. **No pricing, no
paywall, and no tier names are introduced here.**

## Scope
- An `EntitlementPlan` concept with a single seeded plan (call it `default`) defining limits:
  generations per day, generations per month, concerts tracked, linked provider accounts.
- `UserEntitlement` linking a user to a plan, defaulting every existing and new user to `default`.
- A `QuotaService` that checks and records usage, in Redis with durable periodic persistence, correct
  under concurrency.
- Enforcement at every expensive operation: playlist generation, setlist.fm-backed band search, and
  provider search.
- **Application-level circuit breakers**, distinct from per-user limits: when the setlist.fm daily
  budget or a provider's quota nears exhaustion, degrade for everyone in a defined way rather than
  failing at a random moment for whoever happens to be next.
- Clear, typed errors when a limit is reached, with the reset time included so the client can say
  something useful.
- Backoffice: per-user usage, current application-wide budget consumption, and the ability to adjust
  an individual user's entitlement (audited, per prompt 08).
- Client: usage visible before a user hits a wall, and a clear message with a reset time when they do.
- Tests: enforcement at each site, concurrency correctness, reset boundaries, circuit-breaker
  behaviour, and admin override.

## Out of scope
- **Pricing, tiers, paywalls, upgrade prompts and billing.** All of it belongs to prompt 23 and
  whatever follows it.
- Payment integration of any kind.
- Naming a "free" or "paid" tier. There is one plan, and it is called `default`.

## Acceptance criteria
- [ ] A user exceeding the daily generation limit gets a clear error naming the reset time, not a
      generic failure.
- [ ] Limits hold under concurrent requests — verified by test.
- [ ] Usage counters reset correctly at period boundaries, across timezones.
- [ ] **One user cannot exhaust the application-wide setlist.fm or YouTube budget** — the reason this
      prompt exists. Covered by test.
- [ ] Application-level circuit breakers degrade predictably rather than failing arbitrarily.
- [ ] Current usage and remaining allowance are visible to the user before they hit a limit.
- [ ] The backoffice shows per-user usage and application-wide budget consumption.
- [ ] An admin entitlement override works and is audited.
- [ ] **No pricing, tier naming or payment concept appears anywhere in the change.**
- [ ] Adding a second plan would require configuration only, not code — demonstrated by a test adding
      one.

## Risks & open questions
- Setting the default limits requires knowing real usage. Instrument first, set limits from observed
  behaviour, and start generous — a limit that blocks legitimate use is worse than one that is slightly
  loose.
- Redis counters can be lost on restart. Decide how much loss is acceptable and persist accordingly;
  perfect accuracy is probably not worth the complexity here.
- Per-user limits and application circuit breakers are genuinely different mechanisms with different
  failure modes. Keep them separate in the code; conflating them makes both harder to reason about.
- Resist the pull toward pricing. The moment a tier gets a name, this becomes a monetization feature
  and inherits every constraint in `docs/external-apis.md` — including the unresolved setlist.fm
  commercial agreement.
