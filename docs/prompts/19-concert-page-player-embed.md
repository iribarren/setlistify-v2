# 19 — Concert page playback

**Command:** `/feature concert-page-player-embed` · **Agent:** `frontend-engineer` · **Depends on:** 11, 16, 18

## Goal
A generated playlist is playable from the concert page — with the *manner* of playback controlled at
runtime by the backoffice flag, not baked into the client.

## Context
**Read `docs/architecture.md` §7 and `docs/external-apis.md` §Spotify before starting.** This feature
has a legal dimension that is not obvious from the code.

Spotify's developer policy distinguishes a *Streaming SDA* — an app that plays Spotify audio, for
which **no commercial use of any kind is permitted** — from a *Non-Streaming SDA*, which creates
playlists and hands off to Spotify to play, and for which advertising and paid access **are**
permitted. Embedding a player plausibly makes Setlistify the former.

While Setlistify is unmonetized this is harmless: the prohibition is on commercial uses, and there are
none. It becomes live the day any revenue is switched on. That is precisely why `playbackMode` is a
runtime flag from prompt 11 — so the conversion to a Non-Streaming SDA is a toggle, not a release.

**Therefore: this client must render whatever `playbackMode` says, with no assumption baked in.**

Building this after prompt 18 is deliberate — the playback surface is designed against two real
providers rather than fitted to one and retrofitted for the other.

## Scope
- A playback component on the concert page that reads `playbackMode` per provider from
  `GET /api/config/providers` and renders accordingly:
  - `embed` — the provider's official iframe/embed widget.
  - `deeplink` — playlist metadata (artwork, track list, length) plus an "Open in <provider>" handoff
    to the provider's own app or site.
  - `off` — playlist metadata only, no playback affordance.
- All three modes implemented and working for **both** Spotify and YouTube, on web, iOS and Android.
- Correct behaviour when the flag changes: a client that has already loaded picks up the change on its
  next config fetch, without a rebuild or a store release.
- Fallbacks: embed blocked by the platform, embed fails to load, the provider app is not installed for
  a deep link, the user is not authenticated with the provider.
- Track list rendered from our own data — so an unavailable embed never leaves the page empty.
- Theming consistent with prompt 02 in both light and dark, insofar as each provider's embed permits.
- Tests: each of the three modes, for each provider, on each platform; flag change picked up; every
  fallback path.

## Out of scope
- Any in-app SDK playback (Spotify Web Playback SDK or equivalent). That is unambiguously a Streaming
  SDA with no available commercial path, and is out of scope permanently unless that changes.
- Playlist editing.
- Sharing — prompt 21.

## Acceptance criteria
- [ ] With `playbackMode = embed`, the provider's embed plays from the concert page on all three
      platforms.
- [ ] With `playbackMode = deeplink`, the handoff opens the provider app or site correctly on all
      three platforms.
- [ ] With `playbackMode = off`, playlist metadata renders with no playback affordance and no broken
      UI.
- [ ] **Changing the flag in `/admin` changes client behaviour with no rebuild** — the property this
      whole design exists for. Verified end to end.
- [ ] Both Spotify and YouTube work in all three modes.
- [ ] A blocked or failed embed falls back to the deep-link presentation rather than an empty region.
- [ ] The track list is always visible regardless of playback mode or embed failure.
- [ ] No SDK-based in-app playback is introduced anywhere.

## Risks & open questions
- **Ask Spotify in writing whether their iframe widget classifies an app as a Streaming SDA.** Their
  own widget is a greyer case than SDK playback since Spotify serves the audio itself. That answer
  decides whether `embed` can survive monetization — record it in `docs/external-apis.md` when it
  arrives.
- Embeds in React Native require a WebView, which behaves differently on each platform and may be
  restricted by app-store policy. Test on real devices.
- Provider embeds bring their own theming and cannot be fully styled. Accept the seam rather than
  fighting it.
- Spotify embeds play 30-second previews for non-Premium users. Make sure the UI does not present that
  as a malfunction.
