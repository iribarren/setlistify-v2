---
name: spec-19-playback-decisions
description: Spec 19 (concert page playback / player embed, 2026-08-26) decisions D-210–D-226 — one pure function reads playbackMode, embedUrl on PlaylistOutput, off governs already-shipped buttons; scoped Spotify-only + web-only embed on 2026-08-26
metadata:
  type: project
---

`docs/specs/2026-08-26-concert-page-player-embed.md` (backlog prompt 19) proposes **D-210 – D-226**.
Status as written: **draft, review requested**. Frontend-primary + one backend field.

The consequential ones:

- **D-211** — `PlaylistOutput.embedUrl` computed at map time via `StreamingProviderLocator` →
  `playlistEmbedUrl()`. Honest finding: spec 10's `playlistEmbedUrl()`/`playlistDeepLink()` shipped
  with **zero consumers**, and the field the client actually opens is `externalUrl`.
- **D-212** — `externalUrl` *is* the deep link; no `deepLink` field added. `playlistDeepLink()` becomes
  its null-fallback, finally giving that port method a consumer.
- **D-213** — `derivePlaybackSurface()` (`frontend/lib/playlist/playback.ts`) is the ONLY reader of
  `.playbackMode` in the client; static test in the shape of spec 17's two-places test.
- **D-214/D-215** — embed failure is an input (`embedFailed`), not a fourth mode; one-way per mount.
  `EMBED_LOAD_TIMEOUT_MS = 8000` because a frame blocked by `X-Frame-Options` fires no error event.
- **D-216 (revised 2026-08-26)** — the native embed is **deferred**. `react-native-webview` is NOT
  added and **Expo Go keeps working**. `PlaybackEmbed.native` renders nothing and reports the embed
  unavailable, so `embed` presents the deep link on iOS/Android through D-215's existing fallback.
  The function's third input was renamed `embedFailed` → **`embedUnavailable`** to cover both.
  R-6 retired; R-6b (embed/deeplink look identical on native) and R-6c (deferral only stays cheap
  while the code holds no provider literal or `Platform.OS` check) replace it.
- **D-218** — **the bug this spec exists to close**: `PlaylistSection.tsx:236` and `ResultCard.tsx:96/113`
  render "Open in <Provider>" *unconditionally*, ignoring `playbackMode`. `off` currently leaks.
- **D-219** — a disabled provider degrades `embed` → `deeplink`, never `off`: `enabled` kills our
  integration (YouTube quota), not the user's link to their own playlist. Legal axis is
  `playbackMode`'s alone (spec 11's D-97).
- **D-222** — provider-neutral caveat copy keyed off `displayName` (Spotify's 30-s previews). Rejected
  adding a sixth field to `GET /api/config/providers` — spec 11's AC-6.4 freezes it at five.
- **D-225 (revised 2026-08-26)** — the manual PR matrix is **9 cells** (3 modes × Spotify × 3
  platforms), not 18. The 9 YouTube cells are listed as an explicit *deferred* block.

**Scope settled 2026-08-26 (user decision, overruling the draft's own recommendation):**

1. **Ship Spotify-only, do not wait for prompt 18.** YouTube's *concrete* behaviour is deferred as
   **AC-1.2b / AC-2.5b** + 9 matrix cells, which prompt 18 **inherits and must discharge**. Rationale
   accepted: provider-agnosticism is proved by the static tests (AC-1.6/7.2/7.3) and a second
   `ProviderConfigOutput` **fixture** (AC-4.4) — no second adapter needed.
2. **Defer the native embed** (see D-216 above).

**Why:** the whole feature exists so converting Setlistify to a Non-Streaming SDA (Spotify's
monetization gate) stays a five-second `/admin` toggle rather than an app-store release.

**How to apply:** highest D-number after this spec is **D-226** — continue from **D-227**. When
writing prompt 18's spec, cite AC-1.2b / AC-2.5b and re-run spec 19's Layer-3 matrix; prompt 18 must
close them **without editing any file spec 19 added** (if it needs to, AC-1.6/7.2 were violated). A
future native-embed feature owns `react-native-webview` + the Expo Go retirement. See
[[spec-11-provider-config-decisions]], [[spec-16-fast-mode-ui-decisions]], [[spec-house-style]].
