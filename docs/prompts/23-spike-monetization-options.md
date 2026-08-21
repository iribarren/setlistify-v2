# 23 — SPIKE: monetization options

**Command:** `/spec spike-monetization-options` · **Agent:** `project-manager-docs` · **Depends on:** 11, 18, 19, 22

## Goal
A decision, with reasoning, on whether and how Setlistify makes money — taken from an informed
position, once the provider landscape and real usage are known.

**This prompt produces a document, not an implementation.** Use `/spec`.

## Context
Monetization was deliberately deferred at project start, because the constraints were unusual enough
that choosing early would have meant choosing blind. **Read `docs/external-apis.md` in full, and its
"Before enabling monetization" checklist in particular, before writing a word of this.**

The short version of why this is not a simple business decision:

- **setlist.fm's API is free for non-commercial use only.** Both advertising and subscriptions are
  revenue. This blocks *any* monetization until an arrangement exists with them, and it has a long
  lead time.
- **Spotify's Streaming vs Non-Streaming SDA classification** determines what is permitted. A
  Streaming SDA may not sell advertising *or* charge for access. Setlistify's iframe embed plausibly
  makes it one — which is exactly why `playbackMode` (prompt 11) is a runtime flag.
- **Apple Music forbids ad-supported monetization outright.** Choosing ads permanently excludes it as a
  future adapter; choosing subscriptions keeps it available.
- **YouTube permits ad-enabled clients**, with conditions about page content.
- **Ad models need scale**, and the external quotas cap scale. This is a genuine tension, not a
  detail.

## Scope of the investigation
- **Re-verify every constraint in `docs/external-apis.md`.** Terms change; the research is from
  2026-08-21 and must not be trusted blindly at decision time.
- **Report the outcome of the setlist.fm commercial conversation.** If no agreement exists, this spike
  concludes "not yet" — and that is a legitimate, valuable result.
- **Report Spotify's answer on the iframe/SDA question**, and state the current `playbackMode` for
  every provider. If embedding makes Setlistify a Streaming SDA, the flag must change *before* any
  revenue is enabled.
- **Evaluate the realistic models** against those constraints and against actual usage data from
  prompt 22: subscription tiers · one-off purchase · advertising · donations/patronage · nothing.
  Advertising and subscriptions each carry the provider consequences above; donations may sidestep
  parts of the framework, which is worth checking rather than assuming.
- **Cost model**: what Setlistify actually costs to run per active user — hosting, quota increases,
  Apple's $99/yr if relevant — so any pricing is grounded rather than guessed.
- **Usage evidence from prompt 22**: how much do real users generate? What would a limit that
  distinguishes a free from a paid experience even look like, and would it leave the free tier
  genuinely useful?
- **Recommend one option**, with the implementation prompts it would require, and the order.

## Out of scope
- Implementing anything. Payment integration, paywalls and tier definitions all follow from the
  decision, in later prompts.
- Renaming or restructuring prompt 22's entitlements before a decision exists.

## Acceptance criteria
- [ ] A written recommendation exists in `docs/specs/`, naming one option.
- [ ] **Every line of the "Before enabling monetization" checklist in `docs/external-apis.md` is
      answered**, not deferred.
- [ ] The setlist.fm commercial position is stated as fact, with a date — not as an assumption.
- [ ] Spotify's SDA answer is recorded, along with the required `playbackMode` for each provider.
- [ ] The Apple Music exclusion consequence of an ad model is stated explicitly.
- [ ] Real usage data from prompt 22 informs the analysis, rather than estimates.
- [ ] A cost-per-user model is included.
- [ ] "Do not monetize yet" is treated as a legitimate outcome and recommended if the constraints say
      so.
- [ ] `docs/external-apis.md` is updated with anything newly verified, whatever the conclusion.

## Risks & open questions
- **The honest answer may be "not yet".** If the setlist.fm agreement is not in place, no model is
  available, and saying so plainly is the correct output of this work.
- Do not let sunk effort drive the conclusion. The provider constraints are what they are, and
  building a paywall that violates a provider's terms puts the whole product at risk of losing API
  access — a far worse outcome than remaining free.
- Check whether donations genuinely sit outside "commercial use" under each provider's terms rather
  than assuming they do. It is the most commonly assumed loophole and the least verified.
- If the recommendation is ads, re-read the Spotify data-targeting restriction and the YouTube
  page-content condition before committing — both narrow what inventory is actually sellable.
