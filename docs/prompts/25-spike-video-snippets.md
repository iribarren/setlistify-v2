# 25 — SPIKE: concert video snippets

**Command:** `/spec spike-video-snippets` · **Agent:** `backend-engineer` · **Depends on:** 20

## Goal
An honest assessment of letting users attach short video clips they filmed at a concert — including
whether the cost, moderation burden and legal exposure make it worth doing at all.

**This prompt produces a document, not an implementation.** Use `/spec`.

## Context
From the original brief's post-MVP list. It fits the product emotionally: the playlist is what was
played, the review is what it felt like, and a ten-second clip is what it looked like.

It is also, by a wide margin, the most expensive and most legally exposed feature proposed for
Setlistify. Everything so far has been text, JSON and links. This introduces user-generated binary
content, which brings storage costs that scale badly, transcoding, moderation obligations, and
copyright questions that belong to third parties rather than to us.

**Assess it honestly, including the option of not building it.** A recommendation against, with
reasoning, is a perfectly good outcome.

## Scope of the investigation
- **Legal position, first.** Concert footage involves the artist's performance rights, the venue's
  policies, and the label's copyright — none of which belong to the person who filmed it. Even private
  storage carries exposure; sharing (prompt 21, 26) carries much more. Establish what is defensible
  before designing anything, and what a takedown process would need to look like.
- **Storage and delivery**: object storage options, CDN, and a realistic cost model. Model it at 100,
  1,000 and 10,000 users, because the shape of that curve is the decision.
- **Transcoding**: whether it is needed, where it runs (the PHP container is not the right place), and
  what it costs.
- **Upload mechanics** from Expo on iOS, Android and web: size limits, resumable uploads, progress,
  and behaviour on poor connections at a venue.
- **Moderation.** User-generated video means the possibility of hosting content that must be removed.
  What is the minimum viable process — reporting, review, takedown — and who performs it, given there
  is one operator?
- **Privacy**: other people are in concert footage and did not consent. What are the obligations, and
  what does that mean for sharing defaults?
- **Constraints that reduce risk**: maximum duration (ten seconds is very different from ten minutes),
  private-by-default, no public sharing, no download.
- **Recommendation**, with a phased option if a limited version is defensible — for example
  private-only, short-duration clips with no sharing path.

## Out of scope
- Implementation.
- Sharing video to social platforms — prompt 26, which depends on this concluding favourably.
- Live streaming, in any form.

## Acceptance criteria
- [ ] A written assessment exists in `docs/specs/` with a clear recommendation, including possibly not
      to build it.
- [ ] **The legal position on concert footage is researched and stated**, not hand-waved.
- [ ] A cost model at 100 / 1,000 / 10,000 users is included, with the assumptions shown.
- [ ] A minimum viable moderation process is specified, sized for a single operator.
- [ ] Privacy implications for non-consenting people in footage are addressed.
- [ ] Upload mechanics are specified for all three platforms, including poor connectivity.
- [ ] Risk-reducing constraints (duration, privacy defaults, sharing restrictions) are proposed.
- [ ] If the recommendation is phased, phase one is genuinely defensible on its own.

## Risks & open questions
- **This is the feature most likely to be wrong to build.** The cost curve is bad, the moderation
  burden falls on one person, and the legal exposure belongs to third parties who did not agree to
  any of it. Say so plainly if that is the conclusion.
- Storage costs are permanent and cumulative in a way nothing else in this product is. A user who
  uploads for a year and then leaves still costs money.
- Consider whether linking to video the user has already posted elsewhere (YouTube, Instagram) gets
  most of the emotional value with almost none of the cost, storage or liability. That may well be the
  recommendation.
- If it is built, private-by-default is not a nicety — it is the difference between a personal archive
  and a publishing platform, with entirely different obligations.
