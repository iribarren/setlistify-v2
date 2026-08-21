# 26 — SPIKE: advanced social integration

**Command:** `/spec spike-advanced-social` · **Agent:** `backend-engineer` · **Depends on:** 21, 25

## Goal
An assessment of posting directly to social platforms — announcing concerts a user plans to attend,
sharing playlists, sharing clips — against the reality of what those platforms' APIs currently permit.

**This prompt produces a document, not an implementation.** Use `/spec`.

## Context
From the original brief's post-MVP list. Prompt 21 already delivers link sharing and the native share
sheet, which covers most real-world sharing at almost no cost. This spike asks whether *direct* API
posting adds enough on top to justify integrating with several platforms that have each spent recent
years restricting exactly this capability.

Approach it with the scepticism this project has applied to every external API so far, because the
pattern is by now familiar: **social platform APIs have moved consistently toward restriction,
paywalling and approval processes over the last several years.** Expect to find that some are
effectively closed, that some require business verification, and that at least one charges for access.
Setlistify has already been reshaped once by exactly this kind of finding (see
`docs/external-apis.md` §Spotify) — that experience is the relevant precedent.

## Scope of the investigation
- **Per platform** — X/Twitter, Instagram/Threads, Facebook, Mastodon, Bluesky, TikTok — establish:
  posting capability via API, access tier and cost, approval or business-verification requirements,
  rate limits, whether media (image, video) posting is supported, and **commercial-use terms**.
- **The honest comparison**: what does direct API posting actually give a user that prompt 21's native
  share sheet does not? Be specific. If the answer is "not much, at considerable cost", that is the
  finding.
- **Auto-posting**, if it looks feasible: announcing a newly tracked concert. Assess the value against
  the very real risk of a product that posts on someone's behalf and embarrasses them.
- **OAuth burden**: each platform is another set of credentials, another token-refresh path, another
  encrypted-token store, and another thing that breaks when a platform changes its terms.
- **Video posting** (from prompt 25, if that spike concluded favourably) — usually the most restricted
  capability of all.
- **Maintenance cost**: these integrations break regularly and without warning. Estimate the ongoing
  burden, not just the build.
- **Recommendation**: which platforms, if any — with "none, the share sheet is sufficient" as a
  legitimate and likely outcome.

## Out of scope
- Implementation.
- Building a social graph inside Setlistify.
- Changes to prompt 21's link sharing.

## Acceptance criteria
- [ ] A written assessment exists in `docs/specs/` with a per-platform table covering capability, cost,
      approval requirements and commercial terms.
- [ ] **Each platform's current access tier and price is stated as researched fact, with a date** — not
      from memory. These change often.
- [ ] The incremental value over prompt 21's native share sheet is stated explicitly per platform.
- [ ] Ongoing maintenance burden is estimated.
- [ ] Auto-posting is assessed on its risks as well as its appeal.
- [ ] If prompt 25 recommended against video, video posting is marked out of scope rather than
      speculated about.
- [ ] "Do nothing further" is treated as a legitimate recommendation.

## Risks & open questions
- **The likely honest conclusion is that the native share sheet already does the job.** It works on
  every platform the user has installed, needs no API access, no approval, no credentials, and never
  breaks when a platform changes its terms. Direct integration would need to clear a high bar.
- Auto-posting on a user's behalf is a trust risk out of proportion to its benefit. If it is proposed
  at all, it should be explicit, per-post, and previewed before sending.
- Any platform requiring business verification collides with the same problem as Spotify's extended
  access — and with the still-unresolved setlist.fm commercial position (see prompt 23).
- Check commercial-use terms even while monetization is deferred, for the same reason as prompt 24:
  building on something that cannot be used commercially forecloses an option quietly.
