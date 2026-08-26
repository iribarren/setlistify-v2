# FEATURE — Concert page playback (the runtime-configurable player surface)

| | |
|---|---|
| **Spec ID** | `2026-08-26-concert-page-player-embed` |
| **Backlog prompt** | `docs/prompts/19-concert-page-player-embed.md` |
| **Command** | `/feature concert-page-player-embed` |
| **Primary agent** | `frontend-engineer` (one small backend field — see D-211) |
| **Type** | **FEATURE — implementation follows this document directly.** One branch `feature/concert-page-player-embed`, one PR (`CLAUDE.md` — *one feature, one spec, one branch*) |
| **Depends on** | `11` provider configuration (approved, merged — `playbackMode`, `GET /api/config/providers`) · `16` fast-mode UI (approved 2026-08-24, merged — `PlaylistSection`, `reserved-playback`, `useProviderConfigs`) · `10` streaming port (`playlistEmbedUrl()` / `playlistDeepLink()`, shipped unconsumed) · `02` design system |
| **Followed by** | `18` YouTube adapter — closes this spec's deferred provider cells (see Dependencies) · a future feature for native embed via WebView (see Out of Scope) |
| **Implemented by** | *(this is the implementation)* |
| **Decisions** | **D-210** – **D-226** |
| **Scope decisions settled 2026-08-26** | **Spotify only** (prompt 18 not built — YouTube's concrete embed/deeplink behaviour deferred) · **no native embed** (D-216 revised: `embed` degrades to `deeplink` on iOS/Android; `react-native-webview` and the Expo Go retirement deferred) |
| **Status** | **Draft — review requested** |

---

## Overview

### What this feature is

Spec 16 left a dashed rectangle on the concert page labelled *Playback — coming later, prompt 19*
(`ReservedSection testID="reserved-playback"`, D-176). This spec fills it.

What goes in the rectangle is **not decided by this spec, and not decided by the client.** It is
decided at request time by one enum column an operator can change from `/admin` in five seconds:
`ProviderSetting.playbackMode` ∈ { `embed`, `deeplink`, `off` }, served to the client on the public
`GET /api/config/providers` endpoint spec 11 shipped.

The user-visible outcome, in one sentence: **a playlist generated from a concert is playable from
that concert's page — and the manner of playback is an operator's toggle, not a release.**

### Why the toggle exists, restated because it is the whole design

Spotify's developer policy classifies third-party apps by whether they play Spotify audio, and the
classification — not the revenue model — decides what monetization is permitted at all
(`docs/external-apis.md` §Spotify):

| | Streaming SDA | Non-Streaming SDA |
|---|---|---|
| What it is | Plays Spotify audio | Creates playlists, hands off to Spotify to play |
| Selling ads / sponsorships | **Prohibited** | Permitted |
| Charging for the app or access | **Prohibited** | Permitted |

Embedding Spotify's player on the concert page plausibly makes Setlistify the former. **While the app
is unmonetized this is harmless** — the prohibition is on commercial uses, and there are none. It
becomes live the day any revenue is switched on.

`playbackMode = deeplink` converts Setlistify to a Non-Streaming SDA **on the next request**. That is
the property this entire feature exists to preserve, and it survives only if the client contains no
assumption about how any provider is played. A single `if (provider === "spotify")` anywhere in this
feature turns a five-second toggle back into an app-store release — which, on iOS, is a week.

**Therefore the client renders whatever `playbackMode` says, for every provider, with no baked-in
assumption.** D-224's static test is what keeps that true after this branch merges.

### What this spec re-decides: nothing that specs 10, 11, 15 or 16 already decided

- `playbackMode`'s vocabulary, storage, cache, invalidation and public shape come from **spec 11**
  (`docs/specs/2026-08-22-backoffice-provider-configuration.md`, D-89–D-105) verbatim. In particular
  D-97 (`enabled` and `playbackMode` are independent axes) and the exactly-five-fields guarantee on
  `GET /api/config/providers` (AC-6.4 there) are constraints on this spec, not choices in it.
- `playlistEmbedUrl()` / `playlistDeepLink()` come from **spec 10**
  (`docs/specs/2026-08-22-streaming-port-and-account-linking.md`, AC-9.7) verbatim. This spec is the
  consumer that spec 10 said prompt 19 would be; the interface does not change.
- Every screen, layout, headline, colour family and the *position* of the playback panel come from
  **spec 15's canvas** (`docs/design/canvas/playlist-flow/ConcertPlaylist.dc.html`), which already
  draws the reserved panel directly beneath the tracklist and says why: *"the playback panel sits
  directly beneath the tracklist (not above, not in a separate tab) since prompt 19's controls act on
  exactly the tracks listed there."* The artboards, not this document, are the visual source of truth.
- The client file layout, the query conventions, the `derive*()`-pure-function pattern and the
  no-provider-literal static test come from **spec 16**
  (`docs/specs/2026-08-24-playlist-fast-mode-ui.md`, D-161–D-181). This spec extends them; it does not
  invent a second way to do any of it.

Where implementation reveals one of those documents was wrong, it is corrected **in that document, in
this branch**, not diverged from silently.

### Two honest findings from reading the current code

**1. The port's playback methods shipped with zero consumers, and the field the client actually uses
is a different one.** `StreamingProviderInterface::playlistEmbedUrl()` and `playlistDeepLink()` exist
and are tested (spec 10, AC-9.7), but nothing calls them. Meanwhile `PlaylistOutput` exposes
`externalUrl`, persisted at creation time from `ProviderPlaylist::$externalUrl`, and *that* is what
`PlaylistSection` and `ResultCard` already open. So the deep-link half of this feature is,
accidentally, already shipped — and `embedUrl` is not exposed at all. D-211 and D-212 resolve this.

**2. The client already renders an ungoverned playback affordance.** `PlaylistSection.tsx:236` and
`ResultCard.tsx:96/113` render **"Open in \<Provider\>"** unconditionally, without reading
`playbackMode`. Today that is harmless because both seeded rows would render it anyway. The moment an
operator sets `playbackMode = off`, those buttons keep working — the toggle silently fails at exactly
the moment it is being used deliberately. **`off` leaking is the specific bug this spec must not
ship**, and D-218 is the decision that closes it.

### Load-bearing rules this spec does not reverse

| Rule (`CLAUDE.md`) | How this design honours it |
|---|---|
| **The streaming port is the only way to reach a provider** | The client never constructs, parses, edits or pattern-matches a provider URL. `embedUrl` and `externalUrl` arrive from the API as opaque strings and are handed to an `<iframe src>` or `Linking.openURL()` unmodified (D-211, D-223). No `Spotify`/`YouTube`/`Apple` symbol appears outside its adapter directory; the backend addition calls the port, never an adapter |
| **Provider state is read at runtime, not baked in** | `playbackMode` is read from `GET /api/config/providers` on every render of the panel, through spec 16's existing `useProviderConfigs` query (D-220). There is no build-time constant, no bundled default, no per-provider branch |
| **Provider credentials never leave the secrets layer** | Nothing in this feature touches a token. Embeds are unauthenticated public player URLs; the handoff is a public https URL. The client gains no new credential-shaped value |
| **The backoffice edits behaviour, never credentials** | This feature consumes exactly one backoffice-edited flag and no other admin state. The five-field config endpoint is not extended (D-222) |
| **Playlist generation degrades, it does not fail** | Applied one level further, to playback: a missing `embedUrl`, a blocked frame, a load failure, a disabled provider and a provider with no embed at all each degrade to the next-best presentation. **No path renders an error, an error colour, or an empty region** (D-214, D-219, AC-5.x) — the same rule spec 16 enforced with D-168 |
| **A user-scoped resource returns 404, never 403** | Untouched. The playback panel reads a playlist the client already holds; no new endpoint, no new ownership surface |
| **The backoffice is not part of the contract** | Nothing here reads `/admin`. The operator's effect arrives only through `GET /api/config/providers` |
| **Generate types from OpenAPI, never hand-roll them** | `PlaylistOutput.embedUrl` (D-211) reaches the client only after `npm run generate:api`; `frontend/lib/playlist/types.ts` aliases the generated schema, as D-177 requires |

### Existing groundwork this design builds on, not around

| Existing | Used how |
|---|---|
| `frontend/components/playlist/PlaylistSection.tsx` (`reserved-playback`, D-176/AC-8.3) | The placeholder is **replaced** by `PlaybackPanel`, in the same position beneath the tracklist. No layout redesign |
| `frontend/lib/playlist/queries.ts` → `useProviderConfigs()` (60 s `staleTime`, unauthenticated, `no-store`) | Reused as-is. The panel adds **no** new fetch and no new cache entry (D-220) |
| `frontend/lib/playlist/view.ts` → `derivePlaylistView()` | The pattern copied exactly: one pure function maps server state to one view state, tested without rendering. `derivePlaybackSurface()` is its sibling (D-213) |
| `frontend/lib/playlist/providerChoice.ts` (D-169) | Establishes "every provider-specific string is read off the server (`displayName`)". The playback copy obeys the same rule (D-222) |
| `frontend/components/DateField.web.tsx` / `.native.tsx`, `frontend/lib/streaming/linkAccount.web.ts` / `.native.ts` | The precedent for a platform fork: it is allowed when the platforms genuinely differ, and there is exactly one per concern. `PlaybackEmbed.web/native` is the third and only new one — and its `.native` half renders **nothing**, reporting the embed as unavailable so the existing fallback takes over (D-216) |
| `frontend/components/state/DegradedState.tsx`, `components/Card`, `Badge`, `Button`, `@/theme` tokens | The panel is built from existing primitives and tokens; no new visual language (D-223) |
| `backend/src/State/PlaylistOutputMapper.php` | The one place a `Playlist` becomes a `PlaylistOutput`, already injecting a collaborator (`NoSetlistCauseFolder`). `embedUrl` is computed here (D-211) |

---

## Goals

1. A generated playlist is **playable from the concert page** in `embed` mode **on web**, for the one
   provider that exists today (**Spotify**). On iOS and Android, `embed` presents the handoff instead —
   deliberately, via the same fallback path an embed-blocked web client takes (D-216).
2. Changing `playbackMode` in `/admin` changes what every already-installed client renders, **with no
   rebuild and no store release** — verified end to end. *This is the goal; the other four support it.*
3. No path through this feature leaves the page empty, broken, or reading like an error.
4. The tracklist — Setlistify's own data — is always visible, whatever playback does.
5. No SDK-based in-app playback exists anywhere in the codebase, and a test keeps it that way.
6. **The code carries no provider assumption**, so prompt 18's YouTube adapter is closed by adding an
   adapter and re-running the matrix — not by editing this feature. Provider-agnosticism is *tested*
   here (AC-1.6, AC-7.2, AC-7.3) even though only one provider is *exercised* here.

> **Scope note.** Goal 1 is deliberately narrower than the backlog prompt's "both providers, three
> platforms". Two carve-outs, both settled on 2026-08-26 and reflected throughout this document:
> **(a)** prompt 18 (YouTube adapter) has not been built, so YouTube's *concrete* embed and deep-link
> behaviour is deferred to that branch; **(b)** the native embed (a WebView) is deferred to a future
> feature, so `embed` on iOS/Android renders the deep-link presentation. Neither carve-out is allowed
> to reach the design: nothing in the client may branch on a provider key, and the platform difference
> is expressed as "this platform has no embed surface", not as a special mode.

---

## User Stories

### US-1 — Play it right here

> As a **user looking at a concert I tracked**, I want to press play on the concert page and hear the
> show, so that the page is where the concert lives rather than a launchpad for another app.

**Acceptance criteria**

- **AC-1.1** When the concert has a playlist and its provider's `playbackMode` is `embed` and the
  playlist has a non-null `embedUrl`, a **playback panel** renders directly beneath the tracklist, in
  the position `ConcertPlaylist.dc.html` reserves, containing the provider's own embed.
- **AC-1.2** The embed plays from the concert page on **web** (`<iframe>`) for **Spotify**, per the
  manual matrix in *Testing*. On **iOS and Android**, `playbackMode = embed` renders the **deep-link
  presentation** (US-2), because the platform has no embed surface in this feature — reached through
  D-215's existing "embed unavailable → deep link" path, not a separate branch (D-216).
- **AC-1.2b** *(deferred to prompt 18)* The same criterion for **YouTube** — a YouTube embed rendering
  and playing on web — is **not closed by this branch** and is not a blocker for it. No YouTube adapter
  exists, so there is no `embedUrl` to render. It is closed by prompt 18's PR re-running the *Testing*
  matrix's deferred rows. Nothing in this feature's code may change to make that possible (AC-1.6).
- **AC-1.3** The embed's `src` / `source.uri` is `PlaylistOutput.embedUrl` **verbatim**. The client
  appends no query parameter, strips none, and inspects none (D-223).
- **AC-1.4** The panel renders a provider-neutral caveat line — *"Playback here depends on your
  \<displayName\> account; you may hear previews rather than full tracks."* — in secondary text, never
  in an error colour, so a 30-second Spotify preview reads as an account limitation and not as a
  malfunction (D-222, AC-5.6).
- **AC-1.5** The embed never becomes the tracklist. The tracklist above it continues to render from
  `PlaylistOutput.tracks` and is unaffected by the panel's state (D-221).
- **AC-1.6** No provider key literal (`"spotify"`, `"youtube"`, …) appears in any file this feature
  adds or edits — asserted by the static test (D-224, AC-7.2).

### US-2 — Take me to my provider

> As a **user whose provider is set to hand off**, I want a clear "Open in \<Provider\>" affordance
> plus enough of the playlist to recognise it, so that I can start playback where my account already
> is.

**Acceptance criteria**

- **AC-2.1** With `playbackMode = deeplink`, the panel renders playlist metadata — name, track count,
  match badge, the tracklist above it — and a primary **"Open in \<displayName\>"** action. No embed
  is mounted. (No WebView is created anywhere in this feature, in any mode — D-216.)
- **AC-2.2** The action opens `PlaylistOutput.externalUrl` via `Linking.openURL()`. On native, an
  `https` URL registered by the provider's app as a universal / app link opens the app; where it is
  not, the OS opens the browser. **The client does not branch on which happened** (D-217).
- **AC-2.3** No custom URL scheme (`spotify:`, `vnd.youtube:`) is ever constructed, and
  `Linking.canOpenURL()` is never called — so "the provider app is not installed" needs no handling
  and no iOS `LSApplicationQueriesSchemes` declaration (D-217).
- **AC-2.4** When `externalUrl` is null (a playlist row whose creation did not complete), the action is
  **absent, not disabled** — the same rule D-186 set for the "View the setlist" button. A control that
  points at nothing is not rendered.
- **AC-2.5** The handoff works on web (new tab), iOS and Android, for **Spotify**. It is also what
  iOS and Android render in `embed` mode (AC-1.2), which makes this the most-exercised path on native.
- **AC-2.5b** *(deferred to prompt 18)* The same criterion for **YouTube**. Not closed by this branch;
  closed by prompt 18 with no change to this feature's code.

### US-3 — Nothing to play, and still a page worth being on

> As a **user of an app whose operator has turned playback off**, I want the concert page to still show
> me my playlist, so that the page degrades rather than breaking.

**Acceptance criteria**

- **AC-3.1** With `playbackMode = off`, the panel renders playlist metadata only: name, track count,
  match badge. **No embed, no "Open in" action, no disabled button, no dashed placeholder, no empty
  region.**
- **AC-3.2** `off` governs **every** playback affordance the client renders for that provider —
  including the existing "Open in \<Provider\>" buttons in `PlaylistSection` and the three in
  `ResultCard` (`result_full`'s primary, the partial variants' "…anyway"). A rendered-tree test asserts
  none of them appears when the provider's mode is `off` (D-218).
- **AC-3.3** "See what's missing", "Delete" and every non-playback action are **unaffected** by
  `playbackMode`. Turning playback off does not turn the playlist off.
- **AC-3.4** The `off` presentation carries no error colour and no apology copy — it is a normal state
  of the product, not a failure (spec 16's D-168, applied here).

### US-4 — The operator flips the flag and the world changes

> As the **owner**, I want changing `playbackMode` in `/admin` to change what every open client renders
> within a minute, so that converting to a Non-Streaming SDA is a toggle rather than a release.

**Acceptance criteria**

- **AC-4.1** With a client already loaded and showing an embed, changing `playbackMode` from `embed` to
  `deeplink` in `/admin` causes that client to render the handoff presentation **on its next config
  fetch**, with no reload, no rebuild, no store release. Verified end to end against a running stack
  (`docker compose up` + `npx expo start`), not only in a test double.
- **AC-4.2** The bound on "next config fetch" is stated and honoured: `useProviderConfigs`'
  `staleTime` of 60 s, **plus** an immediate refetch when the app returns to the foreground, plus
  spec 11's `Cache-Control: no-store` on the endpoint (D-98). Worst case is 60 s; foregrounding is
  immediate (D-220).
- **AC-4.3** All six transitions are exercised: `embed`→`deeplink`, `embed`→`off`, `deeplink`→`embed`,
  `deeplink`→`off`, `off`→`embed`, `off`→`deeplink`. Each changes the rendered tree and none requires a
  remount of the concert route.
- **AC-4.4** The flag is read **per provider**. A concert whose playlist is on provider A is unaffected
  by a change to provider B's mode — asserted with two configured providers in one test. This is a
  **test-fixture** assertion (`derivePlaybackSurface()` takes a `ProviderConfigOutput`, not an adapter),
  so it is closed in this branch without prompt 18: the second provider is a fixture, not a real one.
- **AC-4.5** While the config query is loading or has failed, the panel renders the **`off`
  presentation** — metadata only. Deny by default: an unknown `playbackMode` never produces an embed
  (D-213).

### US-5 — When the embed doesn't show up

> As a **user on a platform or network where the embed cannot load**, I want the page to hand me the
> next best thing, so that I never stare at a blank rectangle.

**Acceptance criteria**

- **AC-5.1** `playbackMode = embed` with a **null `embedUrl`** (the provider has no embed, or the
  playlist has no provider-side id) renders the **deep-link presentation**, not an empty embed frame.
- **AC-5.2** An embed that reports a load error (`onError` on native, `onerror` on web) renders the
  deep-link presentation.
- **AC-5.3** An embed that reports nothing at all within `EMBED_LOAD_TIMEOUT_MS` (**8000**) — the
  blocked-frame case, which fires no error event on web — renders the deep-link presentation (D-215).
- **AC-5.4** The fallback is **one-way and sticky for the session**: once armed for a playlist, the
  panel does not re-attempt the embed on re-render, avoiding a flicker loop between the two
  presentations. It resets on a fresh mount of the route (D-214).
- **AC-5.5** In every fallback the region is **never empty** and never shows an error colour, an error
  icon, or the word "error" — it shows the handoff plus the tracklist that was always there (AC-1.5).
- **AC-5.6** A user who is not signed in to the provider is **not** a fallback case: the embed is
  public, it renders, and AC-1.4's caveat line already covers reduced playback. No authentication check
  is performed and no sign-in prompt is shown.
- **AC-5.7** With the provider `enabled: false`, `embed` degrades to the **deep-link presentation**,
  not to `off` — the user's own playlist in their own account remains reachable while our integration
  is switched off (D-219). `playbackMode = off` still means off.
- **AC-5.8** **On iOS and Android the embed is unavailable by construction**, and that is expressed as
  the *same* input the cases above use — not as a platform branch inside the panel, and not as a fourth
  `PlaybackSurface` variant. `PlaybackEmbed.native` renders nothing and reports unavailability on mount;
  `derivePlaybackSurface()` therefore returns `deeplink` (or `metadata` when `externalUrl` is null,
  AC-2.4) exactly as it does for a blocked web frame (D-216, reusing D-214/D-215). A test asserts the
  native resolution of `PlaybackEmbed` mounts no player and produces the same tree as an `onError`
  fallback on web.

### US-6 — It still looks like Setlistify

> As a **user**, I want the player to sit inside the page's design rather than on top of it, in light
> and dark, so that the concert page reads as one thing.

**Acceptance criteria**

- **AC-6.1** The panel's frame — card surface, radius, border, spacing, type — comes entirely from the
  prompt 02 tokens via `@/theme`; no hard-coded colour, radius or spacing value is introduced.
- **AC-6.2** The panel renders correctly in both light and dark, at the phone and desktop breakpoints
  drawn in `ConcertPlaylist.dc.html`.
- **AC-6.3** The embed's **interior** is the provider's and is not restyled. The client appends no
  theme parameter to `embedUrl` and injects no CSS into the frame (D-223).
- **AC-6.4** The embed container reserves its height before load (a token-derived fixed height, not a
  collapsing box), so the panel does not jump when the frame resolves, and the reserved area is painted
  with a themed surface colour — not left transparent — so a light-themed provider frame does not flash
  against a dark page. On native this reservation never appears: no embed is mounted, so the panel goes
  straight to the handoff presentation with no reserved box and no layout shift (AC-5.8).
- **AC-6.5** Touch targets in the panel are ≥ 44×44 and the panel is reachable and operable by
  keyboard on web, per the prompt 02 accessibility artboard.

### US-7 — Monetization stays reachable

> As the **owner**, I want it to be structurally impossible for this feature to introduce SDK playback
> or a hardcoded provider assumption, so that the legal position remains a configuration decision.

**Acceptance criteria**

- **AC-7.1** **No SDK-based in-app playback is introduced anywhere** — no Spotify Web Playback SDK, no
  YouTube IFrame Player API script, no MusicKit JS, no native playback module.
- **AC-7.2** A static test asserts (a) no provider key literal in `frontend/lib/playlist/playback.ts`
  or `frontend/components/playlist/Playback*`, extending spec 16's D-169 test, and (b) no dependency in
  `frontend/package.json` whose name matches a provider SDK deny-list (`spotify`, `youtube`,
  `googleapis`, `musickit`) (D-224).
- **AC-7.3** `playbackMode` is interpreted in **exactly one place** —
  `derivePlaybackSurface()` in `frontend/lib/playlist/playback.ts` — asserted by a static test in the
  shape of spec 17's `ModeIsBranchedOnInExactlyTwoPlacesTest`: no other file in `frontend/` reads
  `.playbackMode` (D-213).
- **AC-7.4** The backend addition (D-211) reaches a provider only through
  `StreamingProviderInterface`; spec 10's architecture test — no provider symbol outside its adapter
  directory — still passes unchanged.

---

## Technical Approach

### 1. Where the code goes — D-210

Sub-project: **`frontend/` primarily, plus one field on one backend output.** This is not a
frontend-only spec, and pretending it is would mean the client constructing provider URLs — the one
thing `CLAUDE.md`'s streaming-port rule forbids. One branch, one PR, both sides (`CLAUDE.md` — *one
feature, one spec, one branch*).

```
backend/
  src/ApiResource/Playlist/PlaylistOutput.php   ← + embedUrl (D-211)
  src/State/PlaylistOutputMapper.php            ← computes it via the port (D-211, D-212)

frontend/
  lib/playlist/
    playback.ts        ← derivePlaybackSurface(): the ONLY reader of playbackMode (D-213)
    types.ts           ← + PlaybackMode alias of the generated schema enum (D-177)
  components/playlist/
    PlaybackPanel.tsx       ← the panel: chooses presentation, owns fallback state (D-214)
    PlaybackEmbed.web.tsx   ← <iframe>                         (D-216)
    PlaybackEmbed.native.tsx← renders nothing, reports unavailable (D-216)
    PlaylistSection.tsx     ← reserved-playback → <PlaybackPanel/>; "Open in" governed (D-218)
    ResultCard.tsx          ← its three "Open in" actions governed (D-218)
```

**D-210 — the backend change is one field on one class and no new endpoint.** No new resource, no new
operation, no migration, no entity change. If implementation finds it needs more than that, the design
is wrong and the spec is amended before the code is.

### 2. Getting the embed URL to the client — D-211, D-212

`PlaylistOutput` gains exactly one field:

```php
/** The provider's embeddable player URL, or null when the provider offers none (D-211). */
public ?string $embedUrl,
```

**D-211 — `embedUrl` is computed at map time by calling the port, never stored and never built by the
client.** `PlaylistOutputMapper` gains a `StreamingProviderLocator` dependency and calls
`playlistEmbedUrl($playlist->getProviderPlaylistId())`. It is `null` when the playlist has no
provider-side id yet, when the adapter returns null, and when the locator cannot resolve an adapter
(`UnknownProviderException` is caught and folded to null — a playlist for a retired provider must still
render, per *degrades, does not fail*).

Three alternatives were considered and rejected:

| Alternative | Rejected because |
|---|---|
| Client builds the embed URL from `provider` + a playlist id | Puts `https://open.spotify.com/embed/...` in the client. Breaks the port rule, and a provider changing its embed host becomes an app-store release |
| Persist `embedUrl` on the `Playlist` row at creation | A stored URL is a stale URL. The adapter is the authority and it is free to change format; recomputation costs a string interpolation |
| A new `GET /api/playlists/{id}/playback` endpoint | A second round trip for one string the client already has a request for, and a second place ownership must be enforced |

**D-212 — `externalUrl` is the deep link; no second field is added.** `externalUrl` is already
persisted at creation from `ProviderPlaylist::$externalUrl` and is already what `PlaylistSection` and
`ResultCard` open. Adding a `deepLink` field beside it would give the client two URLs meaning the same
thing and no rule for choosing. Instead, `PlaylistOutputMapper` falls back to the port's
`playlistDeepLink($providerPlaylistId)` when the stored `externalUrl` is null — which is how
`playlistDeepLink()` finally acquires the consumer spec 10 promised it (AC-9.7), without a field
rename or a client change.

Regeneration is mandatory before any client code is written:
`cd frontend && npm run generate:api` (`CLAUDE.md` — *regenerate before wiring up*).

### 3. One pure function decides everything — D-213

```ts
export type PlaybackSurface =
  | { kind: "embed"; embedUrl: string }
  | { kind: "deeplink"; url: string }
  | { kind: "metadata" };

export function derivePlaybackSurface(input: {
  provider: ProviderConfigOutput | undefined;  // undefined = config loading or failed
  playlist: PlaylistOutput;
  /**
   * True when this client cannot show an embed for this playlist: the frame errored (D-215),
   * the watchdog expired (D-215), or the platform has no embed surface at all (D-216, native).
   * One input, one meaning — "no embed here, now" — so there is no platform branch downstream.
   */
  embedUnavailable: boolean;
}): PlaybackSurface;
```

**The third input was named `embedFailed` in an earlier draft and is renamed `embedUnavailable`** to
cover the native case without lying: on iOS and Android nothing failed, the surface simply does not
exist in this feature. The semantics, the truth-table rows and the stickiness rule (AC-5.4) are
unchanged — this is a rename plus one additional producer of `true`.

`derivePlaybackSurface()` is the **only** place in `frontend/` that reads `.playbackMode`
(AC-7.3). It is a pure function of three inputs, so the entire truth table is testable without
mounting anything — which is what makes AC-4.3's six transitions and AC-5.x's fallbacks cheap to cover
exhaustively.

The truth table, in full:

| `playbackMode` | `enabled` | `embedUrl` | `externalUrl` | `embedUnavailable` | Result |
|---|---|---|---|---|---|
| `embed` | true | non-null | — | false | `embed` *(web only — see the row below)* |
| `embed` | true | non-null | non-null | **true** | `deeplink` (AC-5.2, AC-5.3, **AC-5.8 native**) |
| `embed` | true | non-null | null | true | `metadata` |
| `embed` | true | **null** | non-null | — | `deeplink` (AC-5.1) |
| `embed` | true | null | null | — | `metadata` |
| `embed` | **false** | — | non-null | — | `deeplink` (AC-5.7, D-219) |
| `embed` | false | — | null | — | `metadata` |
| `deeplink` | — | — | non-null | — | `deeplink` |
| `deeplink` | — | — | null | — | `metadata` (AC-2.4) |
| `off` | — | — | — | — | `metadata` (AC-3.1) |
| *config missing / unknown value* | — | — | — | — | `metadata` (AC-4.5 — deny by default) |

**Platform does not appear in this table, and must not.** iOS and Android reach the second row because
`PlaybackEmbed.native` sets `embedUnavailable`, not because the function knows what a platform is. That
is what keeps a future native-embed feature (Out of Scope) to a single-file change.

**D-213 — `playbackMode` is interpreted in exactly one pure function, and every other file receives a
`PlaybackSurface`.** This is spec 16's `derivePlaylistView()` pattern (D-166) applied to a smaller
problem, for the same reason: a state machine that is a function is testable; a state machine spread
across three components is a bug report.

**D-219 — a disabled provider degrades `embed` → `deeplink`, never to `off`.** `enabled` is a kill
switch on *our* integration — the case it exists for is YouTube exhausting 10,000 units at 3pm
(`docs/external-apis.md` §YouTube). That fact says nothing about whether the user can open a playlist
that already exists in their own account, and blocking the link would be user-hostile theatre. The
legal axis is `playbackMode`'s alone (spec 11's D-97: the two axes are independent), so `off` remains
the only thing that removes the handoff. Spec 11's "temporarily unavailable" state continues to apply
to *generation* affordances, which spec 16 already wired (`DegradedProviderDisabled`); this spec does
not weaken that.

### 4. The fallback is an input, not a fourth mode — D-214, D-215

**D-214 — `embedUnavailable` is a component-local boolean that feeds the pure function.** `PlaybackPanel`
holds `useState(false)`; the embed's failure callbacks set it true; the function then returns
`deeplink` on the next render. There is no "fallback mode", no fourth `PlaybackSurface` variant, and no
copy that explains what went wrong — the user sees the handoff presentation they would have seen if the
operator had chosen it. Transitions are **one-way within a mount** (AC-5.4): a panel that has fallen
back does not retry, because a retry loop between two presentations is worse than either.

`PlaybackEmbed.native` is simply a **third producer** of that `true`, alongside `onError` and the
watchdog: it reports unavailability on mount and renders nothing (D-216). One boolean, three producers,
one consumer — no platform check anywhere but in the filename.

**D-215 — an 8-second watchdog, because a blocked frame is silent.** A cross-origin iframe refused by
`X-Frame-Options`, a corporate proxy, or an on-device content blocker frequently produces *no* event at
all — `onerror` does not fire reliably for a refused frame on the web. Without a timeout, AC-5.5's
"never an empty region" is unenforceable. `EMBED_LOAD_TIMEOUT_MS = 8000` is one named constant in
`playback.ts`, cleared by the first successful load callback. 8 s is chosen as slower than any healthy
embed on a poor connection and faster than a user's patience with a blank box; it is a copy-style
decision, not a measurement, and is cheap to change.

### 5. Exactly one new platform fork, and the native half is empty — D-216

`react-native-web` cannot render an `<iframe>` and React Native has no built-in web view, so this is a
genuine platform difference — the same class as `DateField.web/native` and `linkAccount.web/native`.

| Platform | Implementation |
|---|---|
| Web | `<iframe src={embedUrl} loading="lazy" allow="encrypted-media; clipboard-write; picture-in-picture" referrerPolicy="strict-origin-when-cross-origin" title="…">`, wrapped so react-native-web renders it |
| iOS / Android | **Renders `null` and calls `onUnavailable()` on mount.** No WebView, no dependency, no native module |

**D-216 (revised 2026-08-26) — the native embed is deferred: on iOS and Android the embed surface is
treated as permanently unavailable, so `playbackMode = embed` presents the deep link.** The earlier
draft added `react-native-webview` and accepted the retirement of Expo Go as the cost. That trade is
declined for this feature. `PlaybackEmbed.native` is a five-line component that renders nothing and
reports unavailability; `PlaybackEmbed.web` is the real `<iframe>`. Both export the same props
(`{ url, onLoad, onUnavailable }`), so `PlaybackPanel` stays platform-agnostic and the fallback logic
still exists exactly once.

Why this shape rather than a `Platform.OS` check in the panel:

- **It reuses D-215's mechanism instead of inventing a second one.** "The embed is blocked" and "this
  platform has no embed" are the same fact to every consumer downstream: there is no frame, show the
  handoff. Modelling them as one input keeps the truth table at eleven rows and keeps the native case
  covered by fallback tests that already had to exist (AC-5.8).
- **It keeps the feature provider- *and* platform-agnostic in the code.** Nothing branches on a
  provider key (AC-1.6), and nothing branches on a platform outside a filename — the two properties
  that decide how expensive the deferred work is later.

What this costs and what it buys:

- **Cost.** On iOS and Android an operator's `embed` and `deeplink` produce the same presentation, so
  AC-4.3's six transitions are visibly distinct only on web. That is stated, not hidden (see *Testing*).
- **Buys.** `react-native-webview` is **not** added, **Expo Go keeps working for the whole project**, no
  development build is required by this branch, and the app-store risk of a media-displaying WebView is
  not taken on now. Native embed, and the Expo Go retirement it forces, are a separate future feature
  (*Out of Scope*) whose entire client-side change is one file: `PlaybackEmbed.native`.
- Autoplay is not attempted on any platform: iOS blocks it, and an autoplaying concert page is a bad
  page.

### 6. Governing the affordances that already exist — D-218

`PlaylistSection.tsx:236` and `ResultCard.tsx:96/113` currently render "Open in \<Provider\>"
unconditionally.

**D-218 — every playback affordance in the feature is governed by the same `PlaybackSurface`, including
the ones that already shipped.** `ResultCard` receives a `surface: PlaybackSurface` prop instead of
`externalUrl` and renders its open actions only when `surface.kind === "deeplink"`. `PlaylistSection`'s
open action moves into `PlaybackPanel` entirely, so the tracklist card keeps only "See what's missing"
and "Delete".

This is the largest behavioural change to existing code in this branch, and it is the point of the
feature: `off` that leaves three working buttons on the result screen is not `off`. AC-3.2's
rendered-tree test is the guard.

### 7. Freshness — D-220

**D-220 — the panel subscribes to spec 16's existing `useProviderConfigs()` query; no new fetch, no new
cache, no new invalidation rule.** `PlaylistSection` already holds the result; `PlaybackPanel` receives
the relevant `ProviderConfigOutput` as a prop rather than calling the hook again, so one config fetch
serves the whole concert page. The propagation contract is therefore spec 16's, unchanged: `staleTime`
60 s, refetch on foreground, `no-store` on the wire (spec 11's D-98) so no HTTP cache sits in front of
a kill switch. Worst-case propagation is 60 s; foregrounding is immediate. AC-4.2 states that bound out
loud because "takes effect immediately" is not a testable claim and 60 s is.

### 8. Copy stays provider-neutral — D-222

The one genuinely provider-specific fact in this feature is that **Spotify embeds play 30-second
previews for non-Premium listeners.** Writing that sentence in the client would require a
`"spotify"` literal, which AC-1.6 forbids and D-169 already tests against.

**D-222 — the caveat is written provider-neutrally and keyed off `displayName`, and the five-field
config endpoint is not extended.** The line is *"Playback here depends on your \<displayName\>
account; you may hear previews rather than full tracks."* — true for Spotify, true enough for YouTube
(region-blocked and age-gated videos behave similarly), and it needs no new field.

Rejected: adding a sixth field to `GET /api/config/providers` (e.g. `playbackCaveat`). Spec 11's
AC-6.4 asserts *exactly these five fields, forever*, with a test that fails on any addition —
deliberately, because that endpoint is unauthenticated and will otherwise accumulate. A copy
convenience is not a good enough reason to be the first thing to breach it. If a future feature needs
genuinely per-provider copy, it goes through that test's review gate on purpose.

### 9. The theming seam is accepted, not fought — D-223

**D-223 — the client never modifies a provider URL, including to theme it.** Some providers accept
theme parameters on their embed URLs; using them would mean the client parsing and rewriting a provider
URL, which is exactly the coupling the port rule exists to prevent. If a provider's embed should be
themed, the adapter's `playlistEmbedUrl()` decides that, behind the interface, on the backend.

So the seam is real and accepted: **the frame is ours, the interior is theirs.** The panel's card,
border, radius, spacing, headings and caveat line follow the prompt 02 tokens in both themes (AC-6.1,
AC-6.2); the rectangle in the middle looks like Spotify or YouTube. AC-6.4's reserved, themed container
keeps that from reading as a rendering bug during load.

### 10. Web CSP — D-226

**D-226 — the Expo web build currently ships no CSP, so embeds work on web today; the obligation is
recorded here so a future CSP does not silently break playback.** The security headers from spec 01
(AC-9.4) apply to the API and `/admin`, which are a different origin from the Expo web bundle. When a
CSP is introduced for the web client, its `frame-src` must allowlist the providers' embed hosts — and
that config file is the one legitimate place provider hostnames appear, because it is deployment
configuration, not client source. This is added to `docs/architecture.md` §7 in this branch so it is
found by the person writing that CSP, not by a user reporting a blank player.

---

## Testing

Three layers, because a real provider frame cannot be exercised in Jest and pretending otherwise would
make the central acceptance criterion untestable.

### Layer 1 — the pure function, exhaustively (automated)

`derivePlaybackSurface()` is table-tested over the **complete** cross product of its inputs:
3 `playbackMode` values × `enabled` true/false × `embedUrl` null/non-null × `externalUrl`
null/non-null × `embedUnavailable` true/false × the config-missing case = every row of §3's table, none
skipped. This is where AC-4.3, AC-4.4, AC-4.5, AC-5.1, AC-5.7 and the whole of US-3's logic are proved.

Layer 1 is **provider-blind and platform-blind by construction** — its inputs are a
`ProviderConfigOutput` and two nullable strings — so it is fully closed in this branch. Neither prompt
18 nor a future native embed adds a row to it.

### Layer 2 — rendered trees, both platform resolutions (automated)

`@testing-library/react-native` under `jest-expo`'s web and native project configurations, so
`PlaybackEmbed.web` and `PlaybackEmbed.native` are each actually selected. **The "2 provider fixtures"
below are fixtures, not adapters** — a second `ProviderConfigOutput` with a different `key` and
`displayName` proves the client holds no per-provider assumption without needing prompt 18:

| Test | Asserts |
|---|---|
| `embed` × 2 provider fixtures, **web resolution** | The `<iframe>` is mounted with `embedUrl` **verbatim** (AC-1.3) |
| `embed` × 2 provider fixtures, **native resolution** | No player of any kind is mounted, and the tree equals the `deeplink` tree (AC-1.2, AC-5.8, D-216) |
| `deeplink` × 2 provider fixtures | `Linking.openURL` called with `externalUrl`; **no** iframe mounted (AC-2.1, AC-2.2) |
| `off` × 2 provider fixtures | No embed, no open action, no disabled control, no `ReservedSection` (AC-3.1) |
| `off` on `ResultCard` | None of its three open actions render (AC-3.2) |
| Flag change mid-mount | Re-rendering with a changed `ProviderConfigOutput` changes the tree without a remount (AC-4.1, AC-4.3) |
| `onError` → deeplink | Fallback presentation, no error colour anywhere in the tree (AC-5.2, AC-5.5) |
| Timer advanced past 8 s with no load callback | Fallback presentation (AC-5.3) |
| Fallback stickiness | A subsequent re-render does not re-mount the embed (AC-5.4) |
| Every mode and every fallback | The tracklist is present in the tree (AC-1.5, AC-5.5) |
| Light and dark theme | Snapshot in both; no hard-coded colour literal in the feature's source (AC-6.1, AC-6.2) |
| Static: provider literals | Extends spec 16's D-169 test to the new files (AC-1.6, AC-7.2) |
| Static: `playbackMode` readers | Exactly one file reads `.playbackMode` (AC-7.3) |
| Static: `package.json` | No dependency matching the SDK deny-list (AC-7.1, AC-7.2) |

Backend: `PlaylistOutputMapper` unit tests for `embedUrl` non-null, adapter-returns-null, unknown
provider, and no-provider-playlist-id; plus the existing functional test on `GET /api/playlists`
extended for the new field. Spec 10's architecture test must still pass untouched (AC-7.4).

### Layer 3 — the device matrix (manual, checklist in the PR)

**D-225 (revised 2026-08-26) — the matrix is 3 modes × **1 provider** × 3 platforms = nine cells,
verified manually and recorded in the PR description; Jest asserts platform *module resolution*, not
platform *behaviour*.** A real frame on a real iPhone is not simulable, and a green test that claims
otherwise would be worse than an honest checklist. The nine cells this branch closes, each verified
against a running stack with the flag flipped from `/admin`:

| Spotify | web | iOS | Android |
|---|---|---|---|
| `embed` | ☐ iframe plays | ☐ handoff (D-216) | ☐ handoff (D-216) |
| `deeplink` | ☐ | ☐ | ☐ |
| `off` | ☐ | ☐ | ☐ |

The native `embed` cells are **not** blank cells — they assert a *specific* required outcome: the
deep-link presentation, no player, no empty region, no error copy (AC-1.2, AC-5.8). A WebView appearing
there is a failure of this branch.

Plus the one that matters most, done once per platform and written into the PR: **an open client
changing presentation after a `/admin` toggle, with no rebuild** (AC-4.1). On web this is observed as
embed → handoff; on native, where `embed` and `deeplink` render alike, use `off` as the other end of
the transition so the change is actually visible.

#### Deferred cells — not closed by this branch

Carried into the PR description as an explicit deferred block, so a reviewer sees what was *not*
verified rather than inferring it:

| Cell | Deferred to | Reason |
|---|---|---|
| YouTube `embed` / `deeplink` / `off`, all three platforms (nine cells) | **Prompt 18** (YouTube adapter) | No `YouTubeProvider` exists, so there is no `embedUrl`, no `externalUrl` and no `ProviderSetting` row to flip. Closed by prompt 18 re-running this table with no change to this feature's code |
| Spotify `embed` on iOS / Android **rendering a real player** | A future native-embed feature (*Out of Scope*) | Requires `react-native-webview`, a development build and the retirement of Expo Go — declined here (D-216) |

---

## Out of Scope

| Not in this feature | Why / where it lives |
|---|---|
| **Any SDK-based in-app playback** (Spotify Web Playback SDK, YouTube IFrame Player API, MusicKit JS, native players) | Unambiguously a Streaming SDA with **no available commercial path**. Out of scope **permanently**, unless Spotify's policy changes. AC-7.1/AC-7.2 make it a test failure, not a judgement call |
| Playlist editing — reordering, removing a track, swapping a version | Not this feature. Per-row report actions are spec 17's (D-205 declined them); playlist editing generally is unscheduled |
| Sharing the playlist or the concert page | Prompt 21. The `reserved-share` placeholder on the concert screen is untouched |
| Now-playing state, progress, scrubbing, queue, cross-fade | All require SDK playback. See row 1 |
| Playback of a *setlist* that has no playlist, or of an individual track | The unit of playback is the generated playlist, matching what the panel sits beneath |
| **YouTube's concrete embed and deep-link behaviour** — a real YouTube `embedUrl`, its container height, its ads/region behaviour, and the nine YouTube matrix cells | **Prompt 18**, the YouTube adapter, which does not exist yet. This is a *deferral of verification*, not a design exception: the client is required to contain no provider key literal (AC-1.6, AC-7.2) and `derivePlaybackSurface()` takes a `ProviderConfigOutput`, so prompt 18 closes AC-1.2b and AC-2.5b by adding an adapter and re-running the matrix — **it may not require an edit to any file this feature adds** |
| **Native embed via WebView** — `react-native-webview`, a real player inside the iOS/Android app | A **future feature**. Declined here (D-216) because it forces the retirement of Expo Go and a development build on the whole project, and carries app-store review risk, in exchange for one panel. Until then `embed` presents the handoff on native through D-215's existing fallback path. The future feature's client change is one file, `PlaybackEmbed.native.tsx`, plus the dependency and build-workflow docs |
| **Retiring Expo Go / adopting a development build** (`expo prebuild`, EAS development profile) | Same future feature. **This branch adds no native code and no native dependency**, so `npx expo start` with Expo Go keeps working exactly as it does today |
| Apple Music | No adapter exists (`docs/external-apis.md` §Apple Music). The design is provider-agnostic, so it needs no change when one arrives |
| Per-user playback preference | `playbackMode` is global configuration (spec 11's out-of-scope: no per-user provider overrides). A user-level preference would defeat the legal purpose of the flag |
| Extending `GET /api/config/providers` | D-222. Its five-field guarantee is a spec 11 decision with a test behind it |
| Offline playback, downloads, caching provider audio | Not permitted by either provider's terms, and not a thing this product does |

---

## Dependencies

| # | Dependency | State | Consequence if unmet |
|---|---|---|---|
| 1 | **Spec 11** — `ProviderSetting.playbackMode`, `ProviderRegistry`, `GET /api/config/providers` | **Merged** | None. The flag, the endpoint and the `/admin` screen all exist |
| 2 | **Spec 16** — `PlaylistSection`, `reserved-playback`, `useProviderConfigs`, the `derive*()` pattern, the D-169 static test | **Merged** | None |
| 3 | **Spec 10** — `playlistEmbedUrl()` / `playlistDeepLink()` on `StreamingProviderInterface` | **Merged**, implemented by the Spotify adapter, **zero consumers** | None. This spec is the consumer |
| 4 | **The Spotify adapter** — `playlistEmbedUrl()` / `playlistDeepLink()` implemented for a real provider | **Merged.** It is the only provider that exists | None for this branch's nine matrix cells |
| 5 | **Prompt 18 — the YouTube adapter** | **NOT BUILT.** No `YouTubeProvider` exists | **Not a blocker for this branch** (settled 2026-08-26). It *is* the gate on **full multi-provider closure**: **AC-1.2b** and **AC-2.5b**, and the nine deferred YouTube cells of the Layer-3 matrix, stay open until prompt 18 merges. Its PR closes them by re-running the matrix; if it needs to *edit* a file this feature added, AC-1.6 / AC-7.2 have been violated here |
| 6 | **Real iOS and Android devices** (or simulators with the Spotify app installed for the deep-link cells) | Assumed available | AC-2.2's app-vs-browser handoff is unverifiable on web-only testing. Expo Go is sufficient — see the note below |
| 7 | **Regenerated API types** — `npm run generate:api` after D-211's field lands | Part of this branch | The client cannot type `embedUrl` without redeclaring it by hand, which `CLAUDE.md` forbids |
| 8 | **Prompt 02 design tokens** (`docs/design/canvas/`) and `ConcertPlaylist.dc.html`'s reserved panel | Delivered | None |

**Two dependencies the earlier draft had, and this one deliberately does not:**
`react-native-webview` and a development build (`expo prebuild` / EAS). D-216's revision removes both.
**This branch adds no native dependency, so Expo Go continues to run the app** and no developer's
workflow changes. Both return as dependencies of the future native-embed feature (*Out of Scope*).

**Sequencing — settled, read before starting.** The backlog places this feature after 18 deliberately:
*"the playback surface is designed against two real providers rather than fitted to one and retrofitted
for the other."* The decision taken on 2026-08-26 is to **ship now against Spotify**, because the
property the sequencing was meant to protect is enforced by tests rather than by having two adapters
present: AC-1.6 and AC-7.2 fail the build on any provider key literal, AC-7.3 holds `playbackMode`
interpretation to one pure function, and AC-4.4 proves per-provider independence with a **second
provider fixture** — which needs no adapter. What genuinely cannot be faked is a real YouTube frame
rendering, and that is exactly what is deferred, named cell by cell, rather than left implicit.

**The obligation this creates on prompt 18**, recorded here because it is the only thing the deferral
costs: its PR must re-run the Layer-3 matrix for YouTube and check off AC-1.2b and AC-2.5b. It inherits
those criteria; they are not dropped.

---

## Risks and Open Questions

Recorded for the decision log. **The four below are the prompt's own, carried over unresolved** — none
of them blocks implementation, and none should be closed by this spec.

1. **Ask Spotify in writing whether their iframe widget classifies an app as a Streaming SDA.** Their
   own widget is a greyer case than SDK playback since Spotify serves the audio itself. That answer
   decides whether `embed` can survive monetization — record it in `docs/external-apis.md` when it
   arrives.
   **Note:** this feature does not wait on it. Whatever the answer, `playbackMode` is the mechanism
   that acts on it, and the answer changes a flag value rather than any code written here. When it
   arrives it belongs in `docs/external-apis.md` §Spotify, beside the existing open question.

2. **Embeds in React Native require a WebView, which behaves differently on each platform and may be
   restricted by app-store policy. Test on real devices.**
   **Note: this risk is not taken in this feature.** D-216 was revised on 2026-08-26 to defer the
   native embed entirely: no WebView is added, so there is no per-platform WebView behaviour to test
   and no app-store exposure from this branch. The risk transfers intact to the future native-embed
   feature (*Out of Scope*), which must answer it before adding the dependency. Note that the risk's
   own worst case — "if a store rejects it, present the deep link on that platform" — is precisely what
   this feature now does on native by default, which is why deferring costs so little.

3. **Provider embeds bring their own theming and cannot be fully styled. Accept the seam rather than
   fighting it.**
   **Note:** accepted and formalised as D-223 — the frame is ours, the interior is theirs. AC-6.3
   states it as intended behaviour so a reviewer does not file it as a bug.

4. **Spotify embeds play 30-second previews for non-Premium users. Make sure the UI does not present
   that as a malfunction.**
   **Note:** AC-1.4 and D-222 handle this with provider-neutral copy in secondary text. The copy is the
   mitigation; there is no technical fix, and dressing a licensing limit as an error would be worse
   than saying it plainly.

Additional risks surfaced while writing this spec:

| # | Risk | Mitigation |
|---|---|---|
| R-5 | **`off` currently leaks.** Three already-shipped "Open in \<Provider\>" buttons ignore `playbackMode`. Anyone flipping the flag today would believe playback was off while it was not | D-218 governs all of them; AC-3.2's rendered-tree test is the guard. This is the highest-value line in the branch |
| R-6 | ~~**`react-native-webview` ends Expo Go for the whole project**~~ — **retired.** The cost was judged too high for one panel, so D-216 was revised: no WebView, no native dependency, Expo Go keeps working | Closed by the revision, not mitigated. The escape hatch this row proposed ("ship `deeplink` on native, land the embed later") **is** the shipped design. Re-opens as a risk of the future native-embed feature |
| R-6b | **`embed` and `deeplink` are indistinguishable on iOS and Android**, so an operator toggling between them sees no change on native and may believe the flag is broken | Documented as intended in AC-1.2 and D-216, and made explicit in the Layer-3 matrix (the native `embed` cells assert the handoff *by design*). The `/admin` copy is not changed by this feature; if operator confusion shows up in practice, a note on that screen is a cheap follow-up. Note the legally load-bearing transition — anything → `off` — remains visible on every platform |
| R-6c | **The deferral is only cheap while the code stays provider- and platform-agnostic.** A future change adding one `Platform.OS` check or one provider literal to the panel silently makes prompt 18 and the native-embed feature expensive | AC-1.6, AC-7.2 and AC-7.3's static tests fail the build on the provider half. The platform half is guarded by structure rather than a test: the only permitted platform branch is the `PlaybackEmbed.web`/`.native` filename split, and `derivePlaybackSurface()` takes no platform input at all (§3) |
| R-7 | **A blocked embed can be silent**, so "never an empty region" depends on a timeout rather than an event | D-215's 8 s watchdog. The number is a guess and is one named constant |
| R-8 | **The 60 s propagation window is not "immediate"** — an operator flipping the flag during an incident waits up to a minute per open client | Stated as a bound (AC-4.2), not hidden. Shortening it means polling the config endpoint more often, which is a cost paid on every session for a rare event; foregrounding already refetches |
| R-9 | **Two of the three modes are exercised only by manual testing on real devices**, so a regression on iOS can merge green | Layer 1 covers all the *logic*; only rendering is manual. The matrix is checked into the PR description so a reviewer sees which cells were actually verified. Reduced by D-216: with no WebView on native, there is far less untestable surface than the earlier draft had |
| R-10 | **A container height tuned to Spotify's embed may crop or letterbox another provider's**, and only Spotify's is visible while writing it | AC-6.4's reserved height is sized from the artboards **per surface, not globally**. Prompt 18 must verify YouTube's shape in its own matrix run and is free to adjust the reserved height — that is a styling constant, not a provider branch, so it does not breach AC-1.6 |
| R-11 | **A deferred criterion is a criterion that quietly never closes.** AC-1.2b and AC-2.5b live in this spec but must be discharged by another branch | Written into *Dependencies* as an explicit obligation on prompt 18, and into the Layer-3 *Deferred cells* table that goes in this PR's description. Prompt 18's own spec should cite AC-1.2b / AC-2.5b when it is written |

---

## Documentation to update (in this branch, before the PR)

Per `CLAUDE.md`'s mandatory documentation checklist:

- **`docs/architecture.md` §7 (Playback surface)** — replace the forward-looking paragraph with what
  was built: the three presentations, `derivePlaybackSurface()` as the single interpreter, the
  `embed → deeplink` degradation rules (D-214, D-216, D-219) — **including that native has no embed
  surface today and why** — and D-226's `frame-src` obligation for a future web CSP.
- **`docs/external-apis.md`** — §Spotify: note that the embed is now live behind `playbackMode` **on
  web only**, and that the open question in that section is the one that decides its future. §YouTube:
  note that the same flag will govern it once prompt 18 lands, and leave the existing ads-in-embeds
  note as the forward-looking statement it is.
- **`frontend/README.md`** — that `playbackMode = embed` renders a player on web and the handoff on
  native, and **that Expo Go remains sufficient** (no native dependency is added). One sentence, so the
  next person does not re-derive the earlier draft's WebView plan from the architecture doc.
- **OpenAPI** — regenerates from `PlaylistOutput`; do **not** hand-list the field anywhere.
- **No new environment variable**, no new backoffice setting, no deployment change, no new sub-project.
- Run `/doc-check` against the diff before opening the PR.

---

## Recommendation Summary

**Scope, settled 2026-08-26 — these are decisions, not open questions:**

1. **Ship Spotify-only now; do not wait for prompt 18.** YouTube's concrete embed and deep-link
   behaviour is deferred to prompt 18, which inherits AC-1.2b, AC-2.5b and nine matrix cells. The
   *code* stays provider-agnostic and static tests enforce it.
2. **Defer the native embed; D-216 is revised.** No `react-native-webview`, no development build, **Expo
   Go keeps working**. On iOS and Android, `playbackMode = embed` presents the deep link through
   D-215's existing "no embed here" fallback path — modelled as one input (`embedUnavailable`), not a
   new mode and not a platform branch.

| Decision | In one line |
|---|---|
| **D-210** | Frontend-primary, plus exactly one field on one backend output class. One branch, one PR |
| **D-211** | `PlaylistOutput.embedUrl`, computed at map time through the port; never stored, never built by the client |
| **D-212** | `externalUrl` *is* the deep link; `playlistDeepLink()` is its fallback source, finally giving that port method a consumer |
| **D-213** | `derivePlaybackSurface()` is the only reader of `playbackMode` in the client, and it is pure |
| **D-214** | Embed unavailability is an input (`embedUnavailable`) to that function, not a fourth mode; one-way within a mount |
| **D-215** | `EMBED_LOAD_TIMEOUT_MS = 8000` — a blocked frame is silent, so the timeout is what makes "never empty" enforceable |
| **D-216** *(revised)* | One new platform fork, `PlaybackEmbed.web/native` — and the native half renders nothing and reports the embed unavailable, so `embed` presents the deep link on iOS/Android. **No `react-native-webview`, no development build, Expo Go keeps working.** Native embed is a future feature whose client change is this one file |
| **D-217** | https handoff only — no custom scheme, no `canOpenURL`, so "app not installed" needs no code |
| **D-218** | `off` governs **every** playback affordance, including the three that already shipped ungoverned |
| **D-219** | A disabled provider degrades `embed` → `deeplink`, never to `off`; `enabled` gates our integration, not the user's own account |
| **D-220** | Reuses spec 16's config query; propagation bound is 60 s, or immediate on foreground |
| **D-221** | The tracklist is always Setlistify's own data; the panel is strictly additive beneath it |
| **D-222** | Provider-neutral caveat copy keyed off `displayName`; the five-field config endpoint is not extended |
| **D-223** | The client never modifies a provider URL, including to theme it; the frame is ours, the interior is theirs |
| **D-224** | Static tests: no provider literal in the playback module, no provider SDK in `package.json` |
| **D-225** *(revised)* | Layer 1/2 automated; Layer 3 is **nine** device cells (3 modes × Spotify × 3 platforms), manual and recorded in the PR, with the deferred YouTube and native-embed cells listed explicitly beside them — honestly, rather than a green test that proves nothing |
| **D-226** | No CSP on the web bundle today; the `frame-src` obligation is recorded in `docs/architecture.md` §7 before it becomes an outage |

---

## Review requested

**Settled on 2026-08-26 — closed, listed here so the change is visible rather than silent:**

- ~~**Sequencing.** Wait for prompt 18, or ship Spotify-only?~~ → **Ship Spotify-only now.** YouTube is
  deferred to prompt 18 as AC-1.2b, AC-2.5b and nine named matrix cells. The recommendation in the
  earlier draft was to wait; it was overruled deliberately, on the grounds that provider-agnosticism is
  proved by the static tests and a second config *fixture*, not by the presence of a second adapter.
- ~~**D-216 / R-6.** Is retiring Expo Go worth one panel?~~ → **No — the native embed is deferred.**
  `react-native-webview` is not added; `embed` presents the deep link on iOS and Android via D-215's
  existing fallback path. R-6 is retired; R-6b and R-6c record what the deferral costs.

**Still worth a deliberate yes or no before implementation starts:**

1. **D-219 — a disabled provider still offers the handoff.** This is a deliberate reading of spec 11's
   graceful disable: `enabled` is a kill switch on our API usage, not on the user's own playlist. If
   the intent was "disabled means the client shows nothing playable", say so and the row changes.
2. **D-218 — three existing buttons change behaviour.** This is the correctness fix at the heart of the
   branch, and it is also the only change here that alters something a user can already see.

The four questions in *Risks and Open Questions* are carried over from the prompt deliberately and are
**not** resolved by this document — except question 2 (React Native WebViews), which this feature no
longer takes on and hands forward to the future native-embed feature.
