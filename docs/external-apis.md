# External APIs — constraints, quotas and legal position

Researched and verified 2026-08-21. **Read this before proposing anything that changes how
Setlistify reaches an external service, and before enabling any form of monetization.**

These constraints are not incidental. Two of them (setlist.fm's daily budget, Spotify's user cap)
directly shaped the architecture, and one (Spotify's SDA classification) determines what business
models are legally available to the product. Re-verify before acting on any of it — terms change.

---

## setlist.fm

The source of setlist data. There is no alternative provider; this is a single point of dependency.

| | |
|---|---|
| Auth | API key in the `x-api-key` header |
| **Rate limit (standard key)** | **2 requests/second, 1,440 requests/day** |
| Higher tier | Up to 16/s and 50,000/day, granted on request via the API settings page |
| **Commercial use** | **Not permitted on the free terms.** Free for non-commercial projects only; commercial use requires an arrangement — contact partner@setlist.fm |
| Docs | <https://api.setlist.fm/docs/1.0/index.html> |

**Why the daily limit is an architectural constraint.** 1,440 requests/day is the budget for the
*entire application*, not per user. A single Normal-mode playlist generation can consume several
requests (band search, setlist list, setlist detail). A few dozen active users would exhaust the day.
This is why `docs/architecture.md` §5 makes the PostgreSQL setlist cache mandatory and why per-user
quota enforcement (prompt 22) is a correctness feature rather than a billing one.

**Mitigating properties.** Past setlists are immutable history — once cached, they never need
re-fetching. Only "has this band played more shows since?" needs refreshing, and that query is
date-bounded. A well-built cache should push the steady-state request rate far below the ceiling.

**Degradation.** When the daily budget is spent: serve from cache, and tell the user plainly that
fresh setlists are unavailable until the budget resets. Never return an empty result as though the
band had no setlists.

**Action outstanding.** Apply for the higher rate tier, and separately open the commercial-use
conversation with setlist.fm. The second has a long lead time and gates monetization **regardless of
which model is chosen** — advertising revenue and subscription revenue are both revenue.

**Enforcement status (as of `docs/specs/2026-08-22-setlistfm-integration.md`).** The daily budget
and the per-second rate are now enforced in-application, not just documented as a constraint:
`App\Service\Setlist\SetlistFmBudget` is a Redis-backed gate every outbound request passes through
(token bucket + UTC-calendar-day counter + a shared circuit breaker), and it fails **closed** if
Redis itself is unreachable — no code path can exceed the configured limits regardless of process
count. Consumption, cache hit rate and the circuit breaker's state are visible on the backoffice
dashboard (`docs/architecture.md` §9, §11) — the constraint no longer needs a `psql` session to
observe. `SETLISTFM_DAILY_BUDGET`/`SETLISTFM_RATE_PER_SECOND` are read from configuration
(`docs/env-vars.md`); raising them is only valid once the higher tier below is actually granted.

---

## Spotify

The reference adapter: best-documented, best catalog matching, and the service the developer
personally uses. **Not the foundation of a public product** — see the cap below.

| | |
|---|---|
| Auth | OAuth 2.0 (Authorization Code + PKCE) |
| **Development Mode** | **5 allowlisted authenticated users.** App owner must hold Spotify Premium |
| **Extended Quota Mode** | Unlimited users — but see eligibility |
| Docs | <https://developer.spotify.com/documentation/web-api/concepts/quota-modes> · <https://developer.spotify.com/policy> |

### The user cap

Extended Quota Mode is the only mode without a user cap. Since **15 May 2025** its criteria are:

- a legally registered business entity (not an individual),
- an **active, launched service**,
- **at least 250,000 monthly active users**,
- availability in key Spotify markets,
- demonstrated commercial viability and policy adherence.

Review takes up to six weeks. This is a chicken-and-egg wall: you cannot reach 250k MAU while capped
at 5 users. **Treat Extended Quota Mode as unavailable.** The consequence is that a second provider
without a user cap (YouTube, prompt 18) is the gate on any public launch — not billing, not features.

### Streaming vs Non-Streaming SDA — the monetization question

Spotify classifies third-party apps ("SDAs") by whether they play Spotify audio, and the
classification, not the revenue model, decides what monetization is permitted.

| | Streaming SDA | Non-Streaming SDA |
|---|---|---|
| What it is | Plays Spotify audio | Creates playlists, reads data, hands off to Spotify to play |
| Selling ads / sponsorships | **Prohibited** | Permitted |
| Charging for the app or access | **Prohibited** | Permitted |
| In-app payment / e-commerce | **Prohibited** | Prohibited |

Policy text: *"commercial uses are not permitted for [Streaming] SDAs"*, expressly including "the sale
of advertising, sponsorships, or promotions" and "any e-commerce (e.g., in-app payment or
monetization)". For Non-Streaming SDAs the permitted set is "the sale of advertising, sponsorships,
or promotions on the Non-Streaming SDA; the sale of, or sale of access to, a Non-Streaming SDA".

**Where Setlistify sits.** The concert page uses Spotify's iframe embed, which plausibly makes it a
Streaming SDA. **While the app is unmonetized this is harmless** — the prohibition is on commercial
uses, and there are none. It becomes live the day any monetization is enabled.

**Mitigation, already built in.** `ProviderSetting.playbackMode` is a runtime flag, editable from the
`/admin` "Providers" screen (`docs/specs/2026-08-22-backoffice-provider-configuration.md`). Setting it
to `deeplink` removes in-app playback and converts Setlistify to a Non-Streaming SDA on the next
request — no deploy, no app-store release. The edit screen states this consequence inline, at the
moment of the click, rather than only here.

**Open question worth resolving in writing.** Spotify's own iframe widget is a greyer case than SDK
playback, because Spotify serves the audio itself. Ask Spotify directly whether embedding their
widget classifies an app as a Streaming SDA. That answer decides whether `playbackMode` can stay on
`embed` after monetization.

### Token revocation

**Spotify's Web API exposes no token revocation endpoint.** Deleting a linked account locally
(`docs/specs/2026-08-22-streaming-port-and-account-linking.md`, D-81) is authoritative on
Setlistify's side — both encrypted token columns are hard-deleted, no soft delete — but Spotify
access is not revoked on their side by that alone. The user must remove Setlistify from their
Spotify account's Apps page to revoke it there too; the unlink UI says this plainly rather than
implying a "revoke" it cannot perform (AC-3.3).

### Confirmed minimal scope set

The linking flow requests exactly `user-read-private` (identity) and `playlist-modify-private`
(creating/editing the user's own private playlists) — nothing broader, and specifically not
`playlist-modify-public` (D-87: playlists created by the port are private by default). Declared in
one place, `App\Service\Streaming\Spotify\SpotifyScopes` (D-88).

### Advertising data restrictions

Independent of the SDA question: Spotify Content and Spotify data **may not be used to target
advertising**, and may not be transferred to any ad network, directly or indirectly. Contextual
advertising is possible; taste-based audience segments ("fans of this band") are not — which removes
the most valuable inventory a music app would otherwise have.

---

## YouTube (Data API v3)

The adapter that makes a public launch possible, and the only provider whose terms clearly permit
ad-supported monetization.

| | |
|---|---|
| Auth | OAuth 2.0 (Google) |
| User cap | **None** |
| Subscription required | **No** — works for users with no paid music service |
| **Quota** | **10,000 units/day.** A playlist insert costs 50 units, a search 100 → roughly 200 playlist writes/day, fewer once searching is counted |
| Commercial use | Permitted, including **"ad-enabled API Clients"** |
| Docs | <https://developers.google.com/youtube/terms/developer-policies> |

**Trade-off.** No user cap and no subscription requirement — so the free tier actually closes — at the
cost of much weaker catalog matching. YouTube is full of covers, live uploads, lyric videos and
region-blocked items, so `TrackCandidate` confidence scoring matters far more here than on Spotify.

**Quota is the real ceiling** and it is exhaustible mid-day. This is the primary reason
`ProviderSetting.enabled` exists as a runtime kill switch, shipped in
`docs/specs/2026-08-22-backoffice-provider-configuration.md`: flip it off from `/admin` and every
wired call site (linking, token refresh, the public config endpoint) reports the provider
unavailable within one request — no deploy — while users see a clean "temporarily unavailable" state
rather than a wall of errors. Quota increases can be requested from Google.

**Advertising caveats, if an ad model is ever chosen.** Ads may not be sold on a page containing
YouTube API data unless non-YouTube content on that same page carries "sufficient independent value"
(Setlistify's concert records, notes and reviews plausibly qualify; a bare playlist page may not).
YouTube's own ads may not be suppressed in embeds.

**Normal mode's added cost** (`docs/specs/2026-08-25-playlist-normal-mode.md`, D-200/D-201). Setlist
selection itself spends nothing — both suspensions read cached rows, `SetlistGateway` gains no new
caller. The only added provider cost is one bounded, scripted second search per job for CHOICE-band
songs resolved via a cover attribution (`MAX_COVER_RESEARCH = 5`), never an on-demand or free-text
search:

| | Searches | Units | Share of daily quota |
|---|---|---|---|
| Fast, 25-song setlist, cold cache | 25 | 2,500 | 25% |
| Normal, same setlist, no preferences | 25 + ≤5 | ≤3,000 | ≤30% |
| Normal, same setlist, warm `TrackResolution`/`UserTrackPreference` | ~0–5 | ≤500 | ≤5% |
| **Application ceiling, cold** | — | 10,000 | **~3 Normal generations/day, application-wide** |

Three cold Normal-mode generations exhaust YouTube's entire daily quota for every user of the
product — not a Normal-mode defect (Fast mode is already four), and not solved here; flagged for
prompt 18 to design against, alongside the `blocked`/`provider_quota` path above, which already
absorbs exhaustion without failing a job.

---

## Apple Music (MusicKit)

Not in the MVP. A credible third adapter — but **only if the product is never ad-supported**.

| | |
|---|---|
| Auth | Developer token (JWT, ES256) + Music User Token |
| Cost | Apple Developer Program, $99/year |
| User cap | None |
| Subscription required | Yes — end users need an active Apple Music subscription |
| Commercial use | Permitted in general |
| **Ad-supported use** | **Prohibited** |

Apple's guidelines: apps using MusicKit "may not require payment or indirectly monetize access to the
Apple Music service through in-app purchases, advertising, or requesting user information", and Apple
Music user data may not be used to target advertising. Apps that gate or monetize Apple Music access
are rejected at review.

**Consequence.** Choosing an ad-supported model rules Apple Music out permanently. Choosing a
subscription model keeps it available, provided the subscription is not sold as access to Apple Music
itself. This asymmetry is a real input to prompt 23.

Development requires Apple hardware and a live Apple Music subscription.

---

## TIDAL — rejected

TIDAL's developer terms permit use "for the sole purpose of developing and distributing
**non-commercial** applications, websites or services", and prohibit products targeted at business
use. Playlist manipulation support has also been historically incomplete.

Not viable for a product that intends to monetize eventually. Do not build this adapter.

---

## Before enabling monetization — checklist

Prompt 23 (`spike-monetization-options`) may not conclude until every line here is answered. Both
advertising and subscriptions count as commercial use; neither escapes any of it.

- [ ] **setlist.fm commercial agreement in place.** Blocks *all* monetization, both models. Long lead
      time — start early.
- [ ] **Spotify SDA classification confirmed in writing.** If embedding makes Setlistify a Streaming
      SDA, set `playbackMode = deeplink` for Spotify *before* revenue is switched on.
- [ ] **`ProviderSetting.playbackMode` reviewed per provider** and the current values recorded in the
      monetization spec.
- [ ] **If advertising is chosen:** confirm no Spotify data reaches an ad network and no taste-based
      targeting is used; confirm any ad-bearing page carries sufficient independent non-YouTube
      content; accept that Apple Music is permanently excluded.
- [ ] **If subscriptions are chosen:** confirm the subscription is sold as access to Setlistify, not
      as access to any provider's catalog.
- [ ] **Spotify user cap re-checked.** If Extended Quota Mode is still unreachable, confirm the
      product can operate commercially on the non-Spotify adapters alone.
- [ ] **Every quota re-measured against projected paid volume**, not current volume.

## Change log

| Date | Change |
|------|--------|
| 2026-08-21 | Initial research. Verified Spotify quota modes and SDA policy, setlist.fm rate limits and non-commercial terms, YouTube quota and ad-enabled client policy, Apple MusicKit advertising prohibition, TIDAL non-commercial restriction. |
| 2026-08-22 | setlist.fm's daily budget and rate limit are now enforced in-application (`docs/specs/2026-08-22-setlistfm-integration.md`), not just documented — see the updated setlist.fm section above. No terms change; the higher-tier application and commercial-use conversation remain outstanding. |
| 2026-08-22 | Streaming port and account linking shipped (`docs/specs/2026-08-22-streaming-port-and-account-linking.md`). Confirmed Spotify exposes no token revocation endpoint (§Spotify, new "Token revocation" subsection) and recorded the confirmed minimal scope set (`user-read-private`, `playlist-modify-private`). No change to the SDA/quota-mode position above. |
