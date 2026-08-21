# 18 — YouTube provider adapter

**Command:** `/feature youtube-provider-adapter` · **Agent:** `backend-engineer` · **Depends on:** 10, 11, 12, 14

## Goal
A second streaming provider behind the existing port — which is what allows more than five people to
use Setlistify, and what makes the product viable independently of Spotify's approval.

## Context
**This is the launch gate. Read `docs/external-apis.md` §Spotify and §YouTube before starting.**

Spotify's Development Mode caps the application at **5 allowlisted users**, and Extended Quota Mode
requires a registered business with a launched service and **250,000 MAU** — a wall that cannot be
climbed from a standing start. Until a second provider exists, Setlistify is a five-person app
regardless of how good it is.

YouTube is the right second provider for three reasons: no user cap, no subscription requirement (so
users with no paid music service can use the product at all), and terms that clearly permit commercial
use including ad-supported models.

This prompt is also the **test of the port abstraction**. If prompt 10 did its job, this is one new
directory plus configuration. If it requires changes outside `Service/Streaming/`, that is a finding
worth reporting — and fixing.

## Scope
- `YouTubeProvider` implementing `StreamingProviderInterface`, in its own directory. No changes to
  consuming code.
- Google OAuth 2.0 with the narrowest scopes that permit playlist creation.
- `searchTrack()` against YouTube Data API v3, with confidence scoring calibrated per prompt 12's
  recommendation — **YouTube's catalog is much noisier than Spotify's**: covers, live fan uploads,
  lyric videos, full-album uploads, region-blocked items. Naive scoring will produce bad playlists.
- Playlist creation and track insertion, preserving setlist order.
- `playlistEmbedUrl()` and `playlistDeepLink()` for prompt 19's playback surface.
- **Quota accounting**: 10,000 units/day, with a search costing 100 and a playlist insert 50. Track
  consumption in Redis against `YOUTUBE_DAILY_QUOTA_UNITS`, refuse or degrade before overrunning, and
  surface the current figure in the backoffice.
- The `ProviderSetting` row seeded by prompt 11 activated, with `playbackMode` set deliberately.
- Region handling: a track available to one user may be blocked for another. Handle per prompt 13's
  taxonomy.
- Tests: the full generation pipeline against YouTube, quota accounting, region restriction, and match
  quality against prompt 12's fixture set with YouTube-specific expectations.

## Out of scope
- Apple Music — a separate future adapter, and one that ad-supported monetization would rule out
  entirely (see `docs/external-apis.md` §Apple Music).
- Changing the port's interface. If it needs changing, that is a prompt-10 defect — report it.
- The playback surface itself — prompt 19.

## Acceptance criteria
- [ ] A user links a Google account and generates a YouTube playlist end to end.
- [ ] **The adapter required no changes outside `Service/Streaming/YouTube/`**, other than
      configuration and DI registration — asserted by reviewing the diff, and by the architecture test
      from prompt 10.
- [ ] Match quality against the fixture set meets the agreed YouTube-specific threshold.
- [ ] Quota consumption is tracked accurately and visible in the backoffice.
- [ ] Approaching the daily quota degrades gracefully; it never overruns into hard API failures.
- [ ] Region-restricted tracks are handled per prompt 13, not surfaced as generic errors.
- [ ] A user with **no** paid music subscription can generate and play a playlist — the property that
      makes YouTube worth having.
- [ ] Both providers can be used by the same user, with the default respected per prompt 11.
- [ ] Disabling YouTube in `/admin` degrades gracefully, per prompt 11.

## Risks & open questions
- **Catalog noise is the real work here**, not the API integration. Expect to spend most of the effort
  on scoring, and expect prompt 12's Spotify-tuned thresholds to need separate calibration.
- 10,000 units/day is tight: at 100 units per search, a single 25-song generation can cost 2,500+
  units. That is roughly **four generations per day** for the whole application before requesting an
  increase. Do the arithmetic early — it may be the binding constraint on launch, not Spotify.
- Request a YouTube quota increase from Google as soon as the integration works. Like setlist.fm's
  rate tier, it costs nothing to ask and changes the ceiling materially.
- Consider whether YouTube search should lean on prompt 24's richer metadata (canonical song titles
  from MusicBrainz) to cut the candidate noise. If so, note the dependency; do not build it here.
