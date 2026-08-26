# Setlistify — Frontend

An [Expo](https://expo.dev) app (React Native + `react-native-web`), [Expo Router](https://expo.github.io/router/),
and TypeScript strict. One codebase serves web, iOS and Android — there is no `*.ios.tsx` /
`*.android.tsx` / `*.web.tsx` fork anywhere in this app (AC-1.8), and none should ever be added
without stopping to reconsider first (D-15).

**SDK / stack**: Expo SDK 57, React 19.2, React Native 0.86, Expo Router (typed routes enabled),
TypeScript ~6.0 (strict). See `package.json` for exact versions.

This app is **deliberately not containerized** — decision D-3, `docs/architecture.md`. Run it on
the host; it reaches the containerized backend at `EXPO_PUBLIC_API_URL`.

## Commands

Run from `frontend/`:

| Command | Does |
|---|---|
| `npm start` (or `npx expo start`) | Starts the Metro dev server — press `w` for web, `i` for iOS, `a` for Android, or scan the QR code with Expo Go |
| `npm run web` / `npm run ios` / `npm run android` | Starts directly on one platform |
| `npm run lint` | ESLint (flat config, `eslint-config-expo` + Prettier compatibility) — the same command CI runs |
| `npm run typecheck` | `tsc --noEmit`, strict mode |
| `npm test` | Jest (`jest-expo` preset) + React Native Testing Library |
| `npm run generate:api` | Regenerates `frontend/api/` from `backend/openapi.json` (see below) |
| `npm run generate:api:live` | Same, but fetches the schema from a running backend's `GET /api/docs.jsonopenapi` instead |

All of the above run inside this directory — nothing here runs in a scratch directory or outside
the repo (Execution Policy, root `CLAUDE.md`).

## The API client is generated — never hand-written

`frontend/api/` is generated from the backend's OpenAPI document by `openapi-typescript`
(`npm run generate:api`) and **committed**. **No request or response type is declared by hand
anywhere in `frontend/`** — every type used against the API comes from `frontend/api/`'s `paths` /
`components`. `frontend/api/` is excluded from lint and from test coverage (it's generated output),
but CI regenerates it on every push and fails the build if the committed file has drifted
(`git diff --exit-code -- api/`) — see `.github/workflows/ci.yml`, the `frontend` job.

To regenerate locally:

```bash
docker compose exec backend bin/console api:openapi:export --output=openapi.json
docker compose cp backend:/app/openapi.json backend/openapi.json
cd frontend && npm run generate:api
```

Or, against an already-running backend: `npm run generate:api:live`.

**Whenever a backend API change lands, regenerate the client in the same branch** (root
`CLAUDE.md`, API Contract) — a breaking change becomes a TypeScript compile error here, not a
runtime surprise for a user. `frontend/api/` is never hand-edited; if the generated output looks
wrong, the fix belongs in the backend's API Platform resource metadata or in
`scripts/generate-api.mjs`'s generator config.

## No component calls `fetch` directly

`frontend/lib/api/` is the one place HTTP happens: `apiClient` (an `openapi-fetch` client bound to
the generated `paths` type), a timeout (`AbortController`, default 10s), RFC 7807
(`application/problem+json`) error parsing into a typed `ApiError`, and the header seam
(`apiClient.use(...)` in `lib/api/client.ts`) that `lib/auth/authMiddleware.ts` attaches
`Authorization`/`X-Client-Platform` to and drives the single-flight refresh-on-401 retry from.
Query hooks (e.g. `useHealth`) live here too, built on TanStack Query v5. A screen or component
that needs data calls a hook from `lib/api/`, never `fetch`.

## Authentication — one session module owns every token

`frontend/lib/auth/` (spec: `docs/specs/2026-08-21-auth-and-accounts.md`) is the **only** place a
token is read or written anywhere in this app (AC-8.4) — `grep` for `getAccessToken`/
`setAccessToken`/`refreshTokenStorage` outside `lib/auth/` should find nothing except the wiring
line in `lib/api/client.ts`.

- **`SessionProvider`/`useSession()`** (`lib/auth/SessionProvider.tsx`) — a React context holding
  `status` (`"restoring" | "authenticated" | "unauthenticated"`) and the current user, plus
  `login`/`register`/`logout`/`requestPasswordReset`/`confirmPasswordReset`/
  `confirmEmailVerification`/`resendEmailVerification`. It is the only sanctioned way a screen
  performs an auth action — no screen calls `lib/auth/api.ts` directly.
- **The access token lives in memory only** (`lib/auth/tokenStore.ts`), never `AsyncStorage`,
  never `localStorage` (D-18). It does not survive a reload; that's what restore is for.
- **The storage adapter is the one platform-branched module in this app** (`storage.native.ts` /
  `storage.web.ts`, D-18's exception to AC-1.8's "no platform fork" rule): native persists the
  refresh token in `expo-secure-store`; web's adapter is inert by construction — the refresh token
  lives only in the httpOnly, `Secure`, `SameSite=Strict` cookie the backend sets on `/api`
  responses, which this client never reads. `expo-secure-store` passed the D-15 web-support gate
  by never being imported on web at all (Metro/Jest platform-extension resolution picks
  `storage.web.ts` there instead) — `tsconfig.json`'s `moduleSuffixes` makes `tsc` resolve the same
  way.
- **`X-Client-Platform: native|web`** is attached in exactly one place
  (`lib/auth/authMiddleware.ts`), never per call site — it tells the backend whether to return the
  refresh token in the response body (native) or httpOnly-cookie-only (web).
- **Single-flight refresh-on-401** (`lib/auth/refreshCoordinator.ts` + `authMiddleware.ts`):
  concurrent 401s join one in-flight `/api/token/refresh` call and each retries its own request
  once against the new access token; a refresh that itself fails clears the session and emits
  `sessionExpired` (`lib/auth/sessionEvents.ts`), which `SessionProvider` reacts to by flipping
  `status` to `"unauthenticated"` — routing is a consequence of that state change, not an
  imperative navigation call from non-React code.
- **Routing**: `app/(auth)/` (login, register, forgot-password, reset-password) and `app/(app)/`
  (everything requiring a session — currently just `home`) are Expo Router groups, each with a
  `_layout.tsx` redirect guard reading `useSession().status`. The root `app/_layout.tsx` renders
  the canvas `LoadingState` while `status === "restoring"` so neither guard evaluates — and no
  screen flashes — before cold-start restore has settled. `app/index.tsx` (health) and
  `app/verify-email.tsx` stay outside both groups, reachable regardless of session state — the
  email-verification link must not bounce through a login redirect.

## Design tokens — the canvas is the source of truth

`frontend/theme/` is a typed transcription of `docs/design/canvas/` (colors, typography, spacing,
radius, elevation) — see `theme/README.md` for the full rule. In short: **no raw hex value and no
off-scale spacing number in a component file.** An ESLint rule (`no-restricted-syntax` in
`eslint.config.js`) blocks hex literals outside `theme/`, `__tests__/`, and config files; a token
change starts on the canvas, not in code.

Colors are read through `useTheme()`, never imported as a fixed palette — that's the one place
light/dark resolution happens. Dark mode follows the OS setting only; there is no in-app toggle.

## Loading / empty / degraded / error — one vocabulary, four components

`frontend/components/state/` (`LoadingState`, `EmptyState`, `DegradedState`, `ErrorState`) are the
**only** sanctioned way to render those four conditions anywhere in the app — see
`components/state/README.md`. Setlistify's normal case is partial success (a band with no setlist
yet, a playlist matched 14 of 19 songs), so `DegradedState` is deliberately never styled like
`ErrorState` — no warning triangle, no amber/red.

## Dependencies — the web-support gate (D-15)

Before adding any dependency to this app: confirm it documents `react-native-web` support, and
render it on web during implementation. A dependency that fails this gate is not worked around with
a platform-forked file (`*.ios.tsx` etc., forbidden by AC-1.8) — stop and reconsider or report.

Currently approved, all gate-checked: `expo-router`, `expo-font` (+ `@expo-google-fonts/*` for the
bundled OFL weight files, D-13), `@tanstack/react-query`, `openapi-fetch`, `lucide-react-native` +
`react-native-svg`, `expo-secure-store` (D-18 — a documented exception: it is genuinely a no-op on
web, which is why the storage adapter branches instead of this dependency being used directly),
`expo-web-browser` (native-only auth session for account linking, D-74 — a no-op import on web,
which is why `linkAccount.web.ts`/`linkAccount.native.ts` branch instead of calling it directly).

## Testing

Jest (`jest-expo` preset) + React Native Testing Library. HTTP is stubbed at the transport boundary
(`global.fetch`, D-14) rather than with MSW — this exercises the real `openapi-fetch` client, the
real RFC 7807 parsing, and the real generated types, and fixtures are typed from `frontend/api/`
rather than hand-written. `@testing-library/react-native`'s `render()` is asynchronous in the
version this project uses — `await render(...)` (or `await` the helper that wraps it).

## Environment

`EXPO_PUBLIC_API_URL` and `EXPO_PUBLIC_ENV` — see `.env.example` and `docs/env-vars.md`, "Frontend
(Expo)". Everything prefixed `EXPO_PUBLIC_` ships inside the JS bundle on every platform; never put
a secret there. A missing `EXPO_PUBLIC_API_URL` fails loudly at startup (`lib/api/client.ts`)
rather than silently requesting a relative URL.

On a physical device, `localhost` resolves to the device itself — set `EXPO_PUBLIC_API_URL` in
`.env.local` to your machine's LAN IP instead.

## Concerts — the app's real home (`feature/concert-tracker-ui`)

`docs/specs/2026-08-21-concert-tracker-ui.md` (D-32–D-41) implements
`docs/design/canvas/screens/` against `frontend/api/schema.d.ts`'s concert endpoints. The Concerts
tab (not Account) is the app's real landing route now — `(auth)/_layout.tsx` redirects an
already-authenticated visitor straight to `/concerts`, and `(app)/index.tsx` does the same for a bare
`(app)` hit. The old health-screen-shaped `home.tsx` scaffold is now `account.tsx` (identity, email
verification, log out) — the second of the shell's two destinations.

- **Route tree**: `app/(app)/concerts/index.tsx` (list — Upcoming/Past sections, skeletons, empty/
  error states, infinite scroll), `concerts/new.tsx` (add), `concerts/[id]/index.tsx` (detail —
  lineup, venue, price, reserved Playlist/Your-note/Share regions for prompts 19–21),
  `concerts/[id]/edit.tsx` (edit + delete). `concerts/_layout.tsx` is a nested `Stack` so list →
  detail → edit push/pop and deep-link normally inside the persistent shell chrome.
- **`lib/concerts/`**: `queries.ts` (`useConcertsSection`/`useConcert`/`useCreateConcert`/
  `useUpdateConcert`/`useDeleteConcert`, D-32/D-33/D-41 — all built on `lib/api/`, never `fetch`
  directly), `mapping.ts` (form model ⇄ generated DTOs; D-38 — the one place money/date conversion
  happens), `validation.ts` (D-31's bounds mirrored client-side, advisory only per D-36),
  `violations.ts` (RFC 7807 `propertyPath` → form field, including indexed `lineup[n].*` paths),
  `errorMessage.ts` (honest, non-enumerating failure copy — D-27's 404-not-403 rule extends here:
  no "forbidden"/"not yours" wording anywhere).
- **The one sanctioned platform fork this branch adds** (D-34, alongside `lib/auth/storage.*`):
  `components/DateField.native.tsx` / `DateField.web.tsx`, behind the shared `DateFieldProps`
  contract in `components/DateFieldTypes.ts`. Web renders the browser's native
  `<input type="date">`; native is `YYYY-MM-DD` text entry pending a vetted cross-platform picker
  dependency clearing the D-15 web-support gate.
- **Phone/desktop shell** (D-39): `app/(app)/_layout.tsx` reads `useWindowDimensions()` against
  `components/nav/breakpoint.ts`'s single 900px threshold — `BottomTabBar` below it, `Sidebar` at or
  above it — never a `Platform.OS` check. Simplified from the canvas's extra collapsed-rail/
  tablet-drawer bands to this one breakpoint; a recorded simplification, not a missed requirement.
- **Components added to the inventory** (`frontend/components/`, AC-9.5): `components/concert/`
  (`ConcertCard`, `SkeletonCard`, `LineupList`, `BandEntryRow`, `DisclosureSection`,
  `ReservedSection`, `ConcertForm`, `DeleteConfirmation`) and `components/nav/` (`BottomTabBar`,
  `Sidebar`).
- **Offline** (D-37): a read falls back to whatever TanStack Query already cached; a write attempted
  offline fails fast (`lib/concerts/errorMessage.ts`'s `status === 0` case) with the user's input
  intact — there is no write queue.

## Streaming accounts — Connections (`feature/streaming-port-and-account-linking`)

`docs/specs/2026-08-22-streaming-port-and-account-linking.md` (US-1, US-2, US-3, US-5) adds a
**Connections** section to `app/(app)/account.tsx`, listing linked streaming accounts and driving
the OAuth round trip against `frontend/api/schema.d.ts`'s `/api/streaming/*` endpoints. The client
never sees a provider token, authorization code or PKCE verifier at any point (D-74) — it only opens
a URL the backend produced and later resolves the opaque, one-time reference the backend hands back.

- **`components/streaming/`**: `ConnectionsSection` (the section itself — owns the whole round trip:
  start the link, open it, resolve the reference, list/reconnect/disconnect), `StreamingAccountRow`
  (one linked account, its status badge, and its actions — AC-2.5's 44×44 targets), and
  `DisconnectConfirmation` (mirrors `components/concert/DeleteConfirmation.tsx`'s shape).
- **`lib/streaming/`**: `queries.ts` (`useStreamingAccounts`/`useStartStreamingLink`/
  `useResolveStreamingLink`/`useUnlinkStreamingAccount` — the last one optimistic, with
  snapshot/rollback reconciliation, mirroring `useCreateConcert`'s onMutate/onError/onSuccess shape),
  `errorMessage.ts` (`describeStreamingError`, `providerDisplayName`, and `revocationFollowUp` — the
  honest, provider-specific copy for D-81's "Spotify has no revocation endpoint" gap).
- **Another sanctioned platform fork** (alongside `lib/auth/storage.*` and `components/DateField.*`):
  `lib/streaming/linkAccount.web.ts` (a full-page redirect to the backend-produced authorization URL)
  / `linkAccount.native.ts` (`expo-web-browser`'s `openAuthSessionAsync`), behind the shared
  `LinkAccount` contract in `linkAccountTypes.ts`. A screen imports the platform-forked module by a
  **relative** path, not the `@/` alias — the eslint import resolver only follows the
  `.native`/`.web` suffix convention through a relative specifier (see `ConnectionsSection.tsx`'s
  import comment).
- **The web return leg** reads the opaque `ref` off the account route's own query params
  (`useLocalSearchParams`, same pattern as `app/verify-email.tsx`'s token) after the backend's
  full-page redirect back to `STREAMING_LINK_RETURN_URL_WEB`, resolves it once, then strips it via
  `router.replace("/account")` so a page refresh can't try to resolve an already-consumed,
  single-use reference again (AC-8.7). The native leg gets the same reference directly from
  `openAuthSessionAsync`'s result URL — no route/query-param involvement.

**Now consumed by prompt 16 (playlist fast-mode UI):** the backend's
`docs/specs/2026-08-22-backoffice-provider-configuration.md` ships a public, unauthenticated
`GET /api/config/providers` (`key`, `displayName`, `enabled`, `playbackMode`, `isDefault`) —
`lib/playlist/queries.ts`'s `useProviderConfigs` reads it with a 60s `staleTime` (D-169) so an
operator's mid-incident toggle reaches an open app quickly, and it is the *only* place a
provider's `displayName` (the one provider-specific string allowed into the UI) comes from.
- `lib/streaming/index.ts`'s `SUPPORTED_PROVIDERS`/`providerDisplayName` remain the streaming-account
  linking feature's own (unrelated) provider list — the playlist feature never imports them.

## Playlist generation — Fast mode (`feature/playlist-fast-mode-ui`)

`docs/specs/2026-08-24-playlist-fast-mode-ui.md` (D-161–D-181) turns prompt 14's seven playlist
endpoints and prompt 15's canvas into the concert detail screen's **Playlist** section plus two
routes. One tap on **Generate playlist** posts a job; the client polls it, then renders whichever of
sixteen designed screens (four result variants, six degraded states, two genuine failures) the
server's state actually describes — never a client-invented error.

- **`lib/playlist/`** — the only module that calls the playlist endpoints:
  - `queries.ts` — `useProviderConfigs`, `useConcertPlaylistJobs`/`pickCurrentJob` (AC-3.2's
    "resolve the current job on entry" priority: active > blocked > most recent terminal),
    `useConcertPlaylists` (the *only* source for the concert page's playlist section — AC-8.4),
    `usePlaylistDetail`, `useStartGeneration`/`useRetryGeneration`/`useCreateAnyway`/`useDeletePlaylist`.
  - `polling.ts` — `usePlaylistJobPolling`: `refetchInterval` seeded from the response's
    `Retry-After` header; its absence is the *entire* termination rule (D-163) — no list of state
    names to keep in sync with the backend. Sends `If-None-Match` once an `ETag` has been seen; a
    304 keeps the cached job untouched (D-164). `AppState` pauses polling in the background and
    refetches immediately on foreground (D-165) — one hook covers web (via React Native Web's own
    `visibilitychange` bridge) and native, no platform fork needed.
  - `view.ts` — `derivePlaylistView(job, playlist, providers, accounts)`: the ONE place server state
    maps to a screen (D-166/D-170), pure and exhaustively tested. `MOSTLY_MATCHED_FLOOR = 0.5` is
    the spec's one new number.
  - `reportCopy.ts` — `describeReportCode(code, params)`: a `Record` typed against the report-code
    union, total over every code, with a safe fallback for a genuinely unknown runtime code (never
    the raw code itself — D-167/AC-5.3). See the file-level comment for why that union is hand-kept
    rather than schema-derived (a documented backend gap, not an oversight).
  - `providerChoice.ts` — `selectProviderCandidates`/`chooseProvider`: `providers ∩ connected
    accounts`, then none/single/default+alternatives/ask-the-user (D-169). No provider key literal
    anywhere in this directory or `components/playlist/` — enforced by a static test.
  - `types.ts` — every wire shape is an alias of `frontend/api/schema.d.ts`.
  - `playback.ts` — `derivePlaybackSurface()`: the ONE place `playbackMode` is read (D-213), pure and
    exhaustively tested. See "Concert page playback" below.
- **`components/playlist/`** — `GenerateTrigger`, `GenerationProgress`, `ResultCard` (the four result
  variants), `ReportList` (only the songs needing a look — matched rows never render), `DegradedState`
  (the six degraded screens plus the two genuine failures; `ErrorState` is used ONLY for
  `failed_generic`/`failed_indeterminate`), `PlaylistSection` (the concert detail screen's Playlist
  card — trigger / compact in-progress status / the permanent tracklist card), `PlaybackPanel` (see
  "Concert page playback" below), and `DeletePlaylistConfirmation` (states plainly that the
  provider-side playlist survives — D-151/D-173).
- **Routes**: `app/(app)/concerts/[id]/playlist.tsx` — ONE route for progress + all sixteen result/
  degraded/failure screens, state-driven via `derivePlaylistView()` rather than four separate routes
  (D-162); `app/(app)/concerts/[id]/playlist-report.tsx` — the report. Row actions ("Pick a version",
  "Skip") are **not wired** here — the report is read-only in Fast mode; they were declined for
  Normal mode too (D-205) — see below.
- **Colour discipline (D-168/AC-4.3)**: partial results and blocked states use only `info`/`warning`
  tokens — never `error`/`destructive`, never the words "error"/"failed"/"problem"/"sorry". A static
  component test asserts this against the rendered tree for every partial and blocked view.

**Known gaps, recorded rather than hacked around** (see `lib/playlist/types.ts` and `view.ts` for the
full comments):
- `no_source_material` can't be split into "band unknown to setlist.fm" vs. "band known, no setlist
  logged yet" client-side — the only signal, the job-level `NO_SETLIST_FOR_BAND` report entry, carries
  just `{ band }`, not *why*. `derivePlaylistView()` defaults to the milder `degraded_no_songs` framing.
- `ResultNothing.dc.html`'s "View the setlist" action isn't wired — neither `PlaylistGenerationJobOutput`
  nor `PlaylistOutput` exposes the selected setlist's `setlistfmId`, so there's nothing to link to yet.

## Playlist generation — Normal mode (`feature/playlist-normal-mode`)

`docs/specs/2026-08-25-playlist-normal-mode.md` (D-188–D-209) wires the mode-chooser sheet spec 16
parked (its Q-2) and adds the two suspension steps spec 13 designed into the *same* route and the
*same* pipeline as Fast mode — no second implementation.

- **`components/playlist/`** gains: `ModeSheet` (the "Generate playlist" / "Or choose it yourself →"
  entry sheet — the words "Fast mode"/"Normal mode" never appear in its copy), `SetlistPicker`
  (US-1: one section per qualifying band, "Same night" pre-selected, a band with `noSetlistCause`
  renders as an explanatory row, not a question — AC-1.8), `VersionPicker` (US-2: auto-resolved songs
  collapse into one reviewable, never-mandatory band; each decision pre-selects the top candidate so
  submitting with zero taps is a legitimate path — AC-2.3), `ConfirmSummary` (D-194: a **client-side**
  sub-step — "Build the playlist" literally calls `POST …/version-choices`, there is no third server
  state), and `ResumeBanner` (D-207: rendered in place of `GenerateTrigger` for a suspended job — no
  inbox, no notification; "Start over" is visually demoted and confirms what it discards — D-208).
- **`lib/playlist/`** gains: `choices.ts` (`usePlaylistChoiceDraft` — the in-progress setlist/version
  choices, persisted per job id via `choicesStorage.native.ts`/`choicesStorage.web.ts` so backgrounding
  or a reload before submission never loses them — D-206), `confidence.ts` (`describeConfidence`: the
  closed label vocabulary → a rendered chip and reason, **no raw confidence number, percentage or
  star ever rendered** — D-204/AC-2.5, asserted by test).
- `view.ts` gains two view states, `choose_setlist` and `choose_versions`, inside the same route
  (D-202) — splitting them into separate routes would turn a server-side suspension into a navigation
  event, the same argument spec 16 made for the result/degraded screens.
- **An empty `CHOICE` band skips both the version step and the confirm screen** (D-195/AC-2.7) — a
  deliberate divergence from the `Confirm.dc.html` artboard, traded for the one property AC-7.1
  demands: a Normal-mode job with nothing ambiguous is state-sequence-identical to Fast mode.
- **The report's per-row actions stay declined** (D-205, closing spec 16's Q-1 the other way from what
  it anticipated): acting on them after a playlist exists is editing a playlist, out of scope for this
  prompt and unsupported by the nine-method streaming port. `ResultMostly`'s CTA stays "See what's
  missing" permanently — decisions belong to `VersionPicker`, before the playlist is built.

**Not built here, on purpose:** a cancel affordance outside "Start over", push notifications on
completion, and a preference-management screen for `UserTrackPreference` (D-198's Q-3) — all
explicitly undesigned or deferred.

## Concert page playback (`feature/concert-page-player-embed`)

`docs/specs/2026-08-26-concert-page-player-embed.md` (D-210–D-226) fills prompt 16's
`reserved-playback` placeholder with `PlaybackPanel`, replacing it directly beneath the tracklist.
Shipped **Spotify-only**; the code itself stays provider-agnostic (no `"spotify"`/`"youtube"` literal
anywhere in the feature, enforced by a static test) so prompt 18's YouTube adapter closes the deferred
cells without editing this feature.

- `playbackMode = embed` renders a real `<iframe>` **on web**. On iOS and Android, `embed` renders the
  same "Open in \<Provider\>" handoff as `deeplink` mode — **no `react-native-webview` is added, and
  Expo Go remains sufficient** for the whole project. `playbackMode = off` renders no playback
  affordance at all, on every platform.
- `derivePlaybackSurface()` (`lib/playlist/playback.ts`) is the only reader of `playbackMode`; it takes
  the playlist, the provider's config, and one boolean (`embedUnavailable` — true on a blocked/errored
  web frame, after an 8s load watchdog, or unconditionally on native) and returns `embed`/`deeplink`/
  `metadata`. A disabled provider degrades `embed` → `deeplink`, never to `off` (D-219).
- `PlaybackEmbed.web.tsx`/`.native.tsx` is the one new platform fork this feature adds — the native
  half renders nothing and reports the embed unavailable on mount, so the fallback path is exercised
  identically to a blocked web frame.
- `PlaylistSection`'s and `ResultCard`'s three pre-existing "Open in \<Provider\>" actions are now
  governed by the same `PlaybackSurface` (D-218) — the bug this feature fixes: `playbackMode = off`
  previously left them rendering unconditionally.

## What's here

```
frontend/
├─ app/
│  ├─ _layout.tsx     theme/query/session providers, font loading, restore-gated loading state
│  ├─ index.tsx        health screen scaffold — unauthenticated, outside (auth)/(app)
│  ├─ verify-email.tsx  email verification confirm — unauthenticated, outside (auth)/(app)
│  ├─ (auth)/           login, register, forgot-password, reset-password — redirects in if already signed in
│  └─ (app)/            breakpoint-driven shell (tab bar / sidebar) around:
│     ├─ index.tsx        redirects to /concerts
│     ├─ concerts/        list, add, detail, edit, playlist, playlist-report (prompts 07, 16)
│     └─ account.tsx      identity, email verification, log out, Connections (prompt 10)
├─ api/               GENERATED — openapi-typescript output, committed, never hand-edited
├─ theme/             colors · typography · spacing · radius · elevation · ThemeProvider
├─ components/        Button, TextInput, Card, ListRow, Badge, Avatar, DateField.native/web
│  ├─ state/          LoadingState, EmptyState, DegradedState, ErrorState
│  ├─ concert/         ConcertCard, SkeletonCard, LineupList, BandEntryRow, DisclosureSection,
│  │                    ReservedSection, ConcertForm, DeleteConfirmation
│  ├─ streaming/       ConnectionsSection, StreamingAccountRow, DisconnectConfirmation
│  ├─ playlist/        GenerateTrigger, GenerationProgress, ResultCard, ReportList, DegradedState,
│  │                    PlaylistSection, DeletePlaylistConfirmation (prompt 16); ModeSheet,
│  │                    SetlistPicker, VersionPicker, ConfirmSummary, ResumeBanner (prompt 17);
│  │                    PlaybackPanel, PlaybackEmbed.native/web (prompt 19)
│  └─ nav/             BottomTabBar, Sidebar, breakpoint
├─ lib/api/           openapi-fetch client, ApiError, timeout, header seam, query hooks
├─ lib/auth/          SessionProvider/useSession, token store, refresh coordinator, storage adapters
├─ lib/concerts/      concert query hooks, DTO mapping, client validation, RFC 7807 violation mapping
├─ lib/streaming/     account-linking query hooks, linkAccount.native/web, error copy
├─ lib/playlist/      playlist query hooks, polling, state→screen mapping, report copy, provider choice,
│                     derivePlaybackSurface
├─ scripts/           generate-api.mjs
└─ __tests__/
```

## Not built yet, on purpose (D-16)

Modals/bottom sheets and toasts are still specified on the canvas but not built as generic
components — the concert feature's delete/discard confirmations and disclosure sections are
inline, purpose-built pieces rather than a general sheet/toast primitive. Notes UI and sharing
remain out of scope. Playlist generation (Fast mode) is now built — see above; Normal mode
(setlist/version selection), in-app playback and the mode-chooser sheet are still out of scope
(prompts 17/19) — see `docs/specs/2026-08-24-playlist-fast-mode-ui.md`, "Out of Scope".
Tabs and date inputs, previously deferred here, shipped with this branch (D-34/D-39) as their first
real consumer.
