# Frontend Skeleton

| | |
|---|---|
| **Spec ID** | `2026-08-21-frontend-skeleton` |
| **Backlog prompt** | `docs/prompts/03-frontend-skeleton.md` |
| **Command** | `/feature frontend-skeleton` |
| **Primary agent** | `frontend-engineer` |
| **Branch** | `feature/frontend-skeleton` |
| **Depends on** | `01` — backend skeleton (merged, `5c70f24`) · `02` — design system foundations (merged, `694adad`) |
| **Status** | **Approved** 2026-08-21 |

---

## Overview

`frontend/` currently contains exactly two files: `.env.example` and `.env.local`. There is no Expo
app, no `package.json`, no `frontend/api/`. The `frontend` CI job is a stub guarded by
`if [ -f package.json ]`.

Both of this feature's inputs are already in place:

- **Prompt 01** shipped `GET /api/health` as an API Platform resource (`backend/src/ApiResource/HealthStatus.php`)
  returning `{ status, database, redis }`, plus an OpenAPI document exportable with
  `bin/console api:openapi:export` and fetchable at `/api/docs.jsonopenapi`.
- **Prompt 02** shipped the design canvas in `docs/design/canvas/` — nine artboards with a complete
  light and dark palette, a type scale on three OFL-licensed families, a 4px spacing scale, radius
  and elevation tokens, a component inventory with per-state renderings, verified WCAG AA contrast
  pairs, a 44×44 touch-target floor, and — designed deliberately, not as an afterthought — the
  **empty, loading, degraded and error** states.

This feature turns those two into a running application. Three outcomes define it:

1. **One codebase, three platforms.** An Expo app with Expo Router and TypeScript strict that runs
   on web, iOS and Android from the same source, with no per-platform UI branch.
2. **A contract that cannot silently drift.** `frontend/api/` is *generated* from the backend's
   OpenAPI document by `npm run generate:api`, committed, and re-checked in CI. No request or
   response type is written by hand. This is the mechanism `CLAUDE.md`'s API Contract section
   describes: a breaking API change becomes a TypeScript compile error, not a runtime surprise in
   front of a user.
3. **The vocabulary every later screen reuses.** Design tokens as a typed theme with system dark
   mode, the base component set, and — the load-bearing part for this product — shared
   **loading / empty / degraded / error** components. Prompts 07, 16, 17 and 19 all render partial,
   missing and stale data as their *normal* case. If those states are invented per screen, the
   product will look broken while working correctly.

This feature ships **no product features**. One screen (`GET /api/health`, rendered) proves the whole
chain: token → component → query → typed client → backend.

## Goals

| Goal | Success looks like |
|---|---|
| One universal codebase | `npx expo start` serves web, iOS and Android from the same source; no `.ios.tsx`/`.web.tsx` fork exists in this branch |
| Types that come from the contract | `frontend/api/` is generated and committed; `grep` finds no hand-written request/response interface anywhere in `frontend/` |
| Drift is caught by CI, not by a person | A backend response-shape change with no regenerated client fails the `frontend` CI job |
| The canvas is transcribed, not approximated | Every colour, size, spacing, radius and elevation value in code traces to a token in `docs/design/canvas/`; no literal hex or magic px in a component |
| Dark mode is real from day one | The app follows the OS setting and renders the dark palette from `Main.dc.html`, with the same AA-verified pairs |
| Degraded ≠ error | A shared `Degraded` component exists and is visually distinct from `Error`, per `States.dc.html` |
| Failure is visible and honest | With the backend down, the home screen shows the error state with a retry, never a blank screen or a spinner that never resolves |
| CI stops being a stub | The `frontend` job installs, typechecks, lints, checks client freshness and runs tests — and is green |

## User Stories

### US-1 — One app, three platforms

> **As a** developer,
> **I want** a single Expo + TypeScript codebase that runs on web, iOS and Android,
> **so that** every later screen is built once instead of three times.

**Acceptance criteria**

- **AC-1.1** `frontend/package.json`, `package-lock.json`, `app.json`/`app.config.ts` and
  `tsconfig.json` exist; the lockfile is committed.
- **AC-1.2** `npx expo start` from `frontend/` launches, and the app renders on **web**, on **iOS**
  (simulator or Expo Go) and on **Android** from the same entry point.
- **AC-1.3** Routing is Expo Router file-based, with an `app/_layout.tsx` root layout and an
  `app/index.tsx` home route.
- **AC-1.4** `tsconfig.json` enables `strict: true`. `npm run typecheck` passes.
- **AC-1.5** No `any` appears in application code. Where a third-party type is genuinely unavailable,
  `unknown` plus a narrowing guard is used, and the reason is commented.
- **AC-1.6** ESLint + Prettier are configured; `npm run lint` passes and is the same command CI runs.
- **AC-1.7** All commands run inside the project tree (`frontend/`). Per **D-3**
  (`docs/architecture.md`) the frontend is deliberately not containerized; nothing is executed
  outside the repository (Execution Policy, `CLAUDE.md`).
- **AC-1.8** No platform-forked source file (`*.ios.tsx`, `*.android.tsx`, `*.web.tsx`) is introduced
  by this branch. If one proves unavoidable, implementation **stops and reports** rather than
  quietly forking the UI.

### US-2 — The design canvas as a typed theme

> **As a** developer building any later screen,
> **I want** the prompt-02 tokens available as typed values,
> **so that** consistency is enforced by the type checker instead of by review discipline.

**Acceptance criteria**

- **AC-2.1** `frontend/theme/` exports typed token objects transcribed from `docs/design/canvas/`:
  - **Colour**, both themes, using the canvas names verbatim — `bg`, `surface-raised`,
    `surface-sunken`, `border-subtle`, `border-strong`, `text-primary|secondary|tertiary`,
    `accent-primary-strong|bright` / `accent-primary`, `accent-secondary-*`, and
    `success|warning|error|info` (`-strong`/`-bright` in light, single value in dark).
  - **Typography**: `display` 44/52·600, `3xl` 32/40·600, `2xl` 26/34·600, `xl` 21/28·600,
    `lg` 18/26·500, `base` 16/24·400, `sm` 14/20·400, `xs` 12/16·600.
  - **Spacing**: `space-1..space-24` on the 4px base unit (4, 8, 12, 16, 20, 24, 32, 40, 48, 64, 80, 96).
  - **Radius**: `sm` 6, `md` 12, `lg` 18, `full`.
  - **Elevation**: `elevation-0..3`, single-layer low-blur shadows only.
- **AC-2.2** Token values match the canvas exactly. A reviewer can diff `frontend/theme/colors.ts`
  against `Main.dc.html` and find no discrepancy.
- **AC-2.3** Colour tokens are accessed through a theme context (`useTheme()`), never imported as a
  fixed palette, so light/dark resolution happens in one place.
- **AC-2.4** The token types are unions, not `string` — an unknown token name is a compile error.
- **AC-2.5** No raw hex value and no off-scale spacing number appears in any component file. A lint
  rule or a documented review check enforces this; the rule is stated in `frontend/README.md`.
- **AC-2.6** The three families — Petrona (display), Manrope (body/UI), Space Mono (ticket data) —
  are bundled as static weight files via `expo-font` and load on all three platforms, with the
  canvas fallback stacks retained (**D-13**). The Google Fonts `<link>` from the canvas is **not**
  used: it is web-only.
- **AC-2.7** `frontend/theme/README.md` states the rule: tokens are transcribed from
  `docs/design/canvas/`, and a token change starts on the canvas, not in code.

### US-3 — Dark mode that follows the system

> **As a** user standing in a dark venue,
> **I want** the app to already be dark,
> **so that** I am not blinded while checking tonight's setlist.

**Acceptance criteria**

- **AC-3.1** The theme resolves from the OS colour scheme (`useColorScheme()`) on web, iOS and
  Android.
- **AC-3.2** Changing the OS setting while the app is open re-renders into the other theme without a
  restart.
- **AC-3.3** The dark palette matches `Main.dc.html` exactly, including the deliberate warm near-black
  `bg` `#0f0a08` — never pure `#000`.
- **AC-3.4** In dark mode, flat surfaces default to `elevation-0` and lean on `border-strong`, per
  `SpacingElevation.dc.html` (a shadow reads as nothing against near-black).
- **AC-3.5** Every text/background pair used by the shipped components is one of the pairs verified
  in `Accessibility.dc.html`; `-bright` tokens are never used for small text.
- **AC-3.6** No in-app theme toggle. System preference only (see Out of Scope).

### US-4 — The base component set, built once

> **As a** developer of prompts 07, 16, 17 and 19,
> **I want** buttons, inputs, cards and list rows already built to the canvas,
> **so that** no screen invents its own.

**Acceptance criteria**

- **AC-4.1** `frontend/components/` provides, per `Components.dc.html` and `Cards.dc.html`:
  `Button` (`primary` | `secondary` | `destructive`, with default/pressed/disabled), `TextInput`
  (default/focus/error/disabled, with label and error message), `Card`, `ListRow`, `Badge` and
  `Avatar`.
- **AC-4.2** Every component's props are typed; variants are unions, not free strings.
- **AC-4.3** Every tappable control has a hit area of **at least 44×44** on every platform, including
  visually smaller controls — the tap area is padded, the control is not shrunk
  (`Accessibility.dc.html`).
- **AC-4.4** Focus is a **3px `accent-primary` ring at 15% opacity**, applied consistently to inputs,
  buttons and tabs, and never conveyed by colour alone.
- **AC-4.5** Components carry accessibility roles and labels (`accessibilityRole`,
  `accessibilityLabel`, `accessibilityState` for disabled/pressed) so screen readers announce them
  correctly.
- **AC-4.6** Icons come from `lucide-react-native` (MIT, per `Icons.dc.html`) at the canvas sizes
  16/20/24/32. **No emoji appears anywhere in the UI.**
- **AC-4.7** Date inputs, tabs, modals/sheets and toasts are **not** built in this branch — see Out
  of Scope and **D-16**.

### US-5 — Loading, empty, degraded and error as first-class components

> **As a** user whose band has no setlist on setlist.fm, or whose playlist matched 14 of 19 songs,
> **I want** the app to tell me plainly what happened,
> **so that** a normal outcome does not look like a broken product.

**Acceptance criteria**

- **AC-5.1** `frontend/components/state/` exports four components — `LoadingState`, `EmptyState`,
  `DegradedState`, `ErrorState` — matching `States.dc.html`.
- **AC-5.2** Each takes a title, a body and an optional action (label + handler), so later screens
  supply copy rather than re-implementing layout.
- **AC-5.3** `DegradedState` is **visually distinct from `ErrorState`**: a progress fraction (e.g.
  `14 / 19`) and an info-blue fill, with **no** warning triangle and no amber/red treatment. This is
  asserted by a test, not left to reviewer memory.
- **AC-5.4** `ErrorState` always offers a retry action.
- **AC-5.5** `LoadingState` is announced to assistive technology (`accessibilityLiveRegion` /
  `aria-live`), so a screen reader user learns that something is in flight.
- **AC-5.6** These four are the **only** sanctioned way to render those conditions. The rule is
  stated in `frontend/components/state/README.md` and in `frontend/README.md`.

### US-6 — A generated API client

> **As a** developer,
> **I want** the client's types generated from the backend's OpenAPI document,
> **so that** a breaking API change fails the build instead of reaching a user.

**Acceptance criteria**

- **AC-6.1** `npm run generate:api` regenerates `frontend/api/` from the backend's OpenAPI document
  using `openapi-typescript`.
- **AC-6.2** The generation source is the document exported by
  `docker compose exec backend bin/console api:openapi:export` (**D-10**), so generation works in CI
  without a running HTTP server. A documented variant generates from the live
  `/api/docs.jsonopenapi` for a developer who already has the stack up.
- **AC-6.3** `frontend/api/` is **committed**, and its header marks it generated with the command
  that produces it.
- **AC-6.4** **No request or response type is declared by hand anywhere in `frontend/`.** All types
  are derived from the generated `paths`/`components` (e.g. `paths['/api/health']['get']['responses']`).
- **AC-6.5** CI regenerates the client and fails if the committed output differs
  (`git diff --exit-code frontend/api/`) — a stale client is a red build, not a discovery (**D-10**).
- **AC-6.6** The failure mode is verified once during implementation: change a backend response
  property, run CI (or the same commands locally), and confirm the frontend job fails.
- **AC-6.7** `frontend/api/` is excluded from lint and coverage — it is generated output, not authored
  code.

### US-7 — One typed way to talk to the backend

> **As a** developer,
> **I want** a single HTTP wrapper with base URL, timeout, error parsing and an auth seam,
> **so that** prompt 04 attaches tokens in one place and no screen ever calls `fetch` directly.

**Acceptance criteria**

- **AC-7.1** `frontend/lib/api/` exports a typed client built on `openapi-fetch` bound to the
  generated `paths` type (**D-11**), so an unknown path or a wrong body shape is a compile error.
- **AC-7.2** The base URL comes from `EXPO_PUBLIC_API_URL` (already declared in `frontend/.env.example`
  and `docs/env-vars.md`). A missing value fails loudly at startup with an actionable message, rather
  than producing a silent request to a relative URL.
- **AC-7.3** RFC 7807 `application/problem+json` responses (the shape prompt 01 established) are
  parsed into a typed `ApiError` carrying `status`, `title`, `detail` and `type`.
- **AC-7.4** A network failure, a timeout and a non-problem-JSON body all produce the same typed
  `ApiError`, so a caller never has to distinguish transport failure from HTTP failure.
- **AC-7.5** Every request has a timeout (default 10s, overridable) implemented with `AbortController`,
  and a timeout surfaces as `ApiError`, never as an unhandled rejection.
- **AC-7.6** There is exactly **one** documented place where request headers are attached — the
  extension point prompt 04 uses for `Authorization`. It exists and is commented; it attaches nothing
  yet.
- **AC-7.7** No component or screen calls `fetch` directly. `frontend/README.md` states the rule.
- **AC-7.8** No credential, token or user identifier is logged by the wrapper on any platform.

### US-8 — Server state with caching, retries and honest states

> **As a** user on a patchy mobile connection,
> **I want** the app to retry sensibly and tell me what it is doing,
> **so that** a flaky network does not look like a dead app.

**Acceptance criteria**

- **AC-8.1** TanStack Query v5 is configured with a `QueryClientProvider` in the root layout
  (**D-12**).
- **AC-8.2** Sensible defaults are set explicitly and commented: `staleTime`, `retry` (with backoff),
  and no retry on 4xx client errors.
- **AC-8.3** Query hooks live in `frontend/lib/api/` (e.g. `useHealth`), not inside components, and
  their return types derive from the generated client.
- **AC-8.4** Query keys follow one documented convention so later features do not each invent one.
- **AC-8.5** Loading, success and error render through the US-5 state components — never ad hoc
  inline markup.

### US-9 — A health screen that proves the chain

> **As a** developer,
> **I want** the home screen to fetch and render `GET /api/health`,
> **so that** "the whole stack is wired correctly" is demonstrated, not assumed.

**Acceptance criteria**

- **AC-9.1** `app/index.tsx` calls `GET /api/health` through the generated client and renders the
  real values of `status`, `database` and `redis` using theme tokens and the base components.
- **AC-9.2** While the request is in flight, `LoadingState` renders.
- **AC-9.3** With the backend stopped (`docker compose stop backend`), `ErrorState` renders with a
  working retry — visibly degraded, never a blank screen or an endless spinner.
- **AC-9.4** When the backend answers `503` (a dependency down, e.g. `docker compose stop redis`),
  the screen shows the **per-dependency** detail — the failing dependency named, the healthy ones
  still shown — rather than collapsing it into a generic failure.
- **AC-9.5** The screen renders correctly at phone width and at desktop width, in both themes
  (prompt 02, "works at phone width and at desktop width").
- **AC-9.6** This screen is explicitly a scaffold. `app/index.tsx` carries a comment saying prompt 07
  replaces it with the concert list.

### US-10 — Tests, and a CI job that is no longer a stub

> **As a** maintainer,
> **I want** the frontend covered by tests that CI runs,
> **so that** a regression in the shared components or the client is caught on push.

**Acceptance criteria**

- **AC-10.1** Jest is configured with the `jest-expo` preset plus React Native Testing Library, and
  `npm test` runs it.
- **AC-10.2** The health screen is tested in **three** states — loading, success, error — asserting
  on user-visible text and accessible roles, not on implementation details.
- **AC-10.3** The state components are tested, including **AC-5.3**: `DegradedState` and `ErrorState`
  are distinguishable.
- **AC-10.4** At least one theme test asserts that a component resolves dark-mode tokens when the
  colour scheme is dark.
- **AC-10.5** HTTP is stubbed at the transport boundary (`global.fetch`), so tests exercise the real
  wrapper, real error parsing and the real generated types (**D-14**).
- **AC-10.6** No test asserts against a hand-written response type; fixtures are typed from
  `frontend/api/`.
- **AC-10.7** The `frontend` CI job in `.github/workflows/ci.yml` loses its `if [ -f package.json ]`
  guards and runs: `npm ci` → `npm run lint` → `npm run typecheck` → the AC-6.5 freshness check →
  `npm test`. `actions/setup-node` gains `cache: npm` with
  `cache-dependency-path: frontend/package-lock.json`.
- **AC-10.8** The freshness check needs the backend's OpenAPI document. Per **D-10** the `backend`
  job exports it as a build artifact and the `frontend` job consumes it, so the frontend job needs
  neither PHP nor a database.

## Technical Approach

**Sub-projects:** `frontend/` (new application) and `.github/workflows/ci.yml` (the `frontend` job
stops being a stub; the `backend` job gains one artifact-upload step). **`backend/src/` is not
touched** — prompt 01 already ships everything this feature consumes.

**Starting state (verified 2026-08-21):**

| Path | Current state |
|---|---|
| `frontend/` | `.env.example` and `.env.local` only — no `package.json`, no source |
| `frontend/.env.example` | Already declares `EXPO_PUBLIC_API_URL` (default `http://localhost:8000`) and `EXPO_PUBLIC_ENV` |
| `docs/env-vars.md` §"Frontend (Expo)" | Both variables documented; drift is enforced by `scripts/check-env-vars-drift.sh` |
| `backend/src/ApiResource/HealthStatus.php` | `GET /api/health` → `{ status, database, redis }`, `200`/`503` both in the OpenAPI document |
| `backend/README.md` | Documents `bin/console api:openapi:export --output=openapi.json` |
| `.github/workflows/ci.yml` | `frontend` job is a stub; Node 20; no npm cache |
| `docs/design/canvas/` | Nine artboards + `canvas.json` (palette, typography, spacing/elevation, components, cards, states, concert card, accessibility, icons) |
| `docs/architecture.md` | **D-3**: the frontend is deliberately not containerized |

**Shape of the work:**

```
frontend/
├─ app/                              Expo Router
│  ├─ _layout.tsx                    ThemeProvider + QueryClientProvider + font loading
│  └─ index.tsx                      health screen (prompt 07 replaces it)
├─ api/                              GENERATED — openapi-typescript output, committed, not linted
├─ theme/                            colors(light|dark) · typography · spacing · radius · elevation
│  └─ README.md                      "tokens are transcribed from docs/design/canvas/"
├─ components/
│  ├─ Button · TextInput · Card · ListRow · Badge · Avatar
│  └─ state/                         LoadingState · EmptyState · DegradedState · ErrorState (+README)
├─ lib/api/                          openapi-fetch client · ApiError (RFC 7807) · timeout ·
│                                    header seam (prompt 04) · useHealth query hook
├─ __tests__/                        health screen (3 states) · state components · theme
├─ app.json / app.config.ts · tsconfig.json (strict) · jest.config · eslint/prettier
└─ README.md                         commands, rules, the web-support gate (D-15)
```

### Decisions

Numbered from **D-10** onward; `D-1`–`D-3` are project-wide (`docs/architecture.md`) and `D-4`–`D-9`
belong to the backend skeleton spec.

**D-10 — Generated types are committed *and* CI fails when they are stale.**
The prompt raises this as an open question and calls the stricter option "worth it". Committing alone
means the client drifts silently until someone runs the script; the whole point of generation is that
drift is impossible. So: commit the output (contributors and CI need it without a running backend)
**and** regenerate in CI, failing on `git diff --exit-code frontend/api/`. Generation reads the
document produced by `bin/console api:openapi:export` rather than an HTTP fetch, because a console
export is deterministic and needs no server — the `backend` CI job uploads it as an artifact and the
`frontend` job downloads it, which keeps the frontend job free of PHP and PostgreSQL. Cost: the
frontend job now depends on the backend job. Accepted — the coupling is the feature.

**D-11 — `openapi-fetch` for the transport, wrapped thinly.**
A hand-rolled `fetch` wrapper would have to re-derive the path/method/body types from the generated
`paths`, which is exactly the hand-written typing AC-6.4 forbids — and it is easy to get subtly wrong.
`openapi-fetch` is by the `openapi-typescript` authors, is runtime-agnostic (it uses the standard
`fetch` present on web, Hermes and modern React Native), is ~6kB, and makes an unknown path a compile
error for free. Setlistify-specific behaviour — base URL, timeout, RFC 7807 parsing, the auth-header
seam — lives in a thin middleware around it, so all of US-7 still has exactly one home.

**D-12 — TanStack Query v5 for server state; no global client-state library.**
The prompt allows "or equivalent". TanStack Query is the mainstream choice, works identically under
react-native-web, and gives caching/retry/stale handling that would otherwise be rebuilt per screen.
Crucially, everything this app shows is *server* state. Adding Redux/Zustand now would create a second
source of truth for data the query cache already owns. If prompt 04 (auth session) or 17 (normal-mode
picker) needs client state, React context or a small store is added *then*, with a reason.

**D-13 — Fonts are bundled with `expo-font`, not linked from Google Fonts.**
`Typography.dc.html` confirms Petrona, Manrope and Space Mono are all SIL OFL, so embedding in the
compiled app is licensed — and it explicitly notes the `<link>` is web-only. Static weight files are
bundled and loaded through `expo-font`, with the canvas fallback stacks kept so the app is legible
before fonts resolve. Only the weights the scale actually uses are bundled (Manrope 400/500/600/700/800,
Petrona 400/400i/600, Space Mono 400/700) — bundle size is a real cost on native.

**D-14 — Tests stub `global.fetch`; no MSW.**
Stubbing at the transport boundary exercises the real client, the real RFC 7807 parsing and the real
generated types — the parts most likely to break. MSW would add service-worker/native polyfill setup
under `jest-expo` for little extra fidelity at this size. Fixtures are typed from `frontend/api/`
(AC-10.6), so a contract change breaks the tests too. Revisit if the fixture surface grows past a
handful of endpoints.

**D-15 — Every dependency passes a web-support gate before adoption.**
The recurring tax of the universal-app approach is a library that works on native and not under
react-native-web (or vice versa). The rule: before adding any dependency, confirm documented
react-native-web support **and** render it on web during implementation. The rule and the current
approved list (`expo-router`, `expo-font`, `@tanstack/react-query`, `openapi-fetch`,
`lucide-react-native` + `react-native-svg`) are recorded in `frontend/README.md`. A dependency that
fails the gate is not worked around with a platform fork (AC-1.8): stop and report.

**D-16 — Only the components the health screen and prompts 04/07 need are built now.**
`Components.dc.html` also specifies tabs, modals/sheets and toasts. Building them here means building
them without a caller — untested against real usage, and likely reshaped on first use. Buttons,
inputs, cards, list rows, badges and avatars have known consumers in prompts 04 and 07; the four
state components are built now precisely *because* they would otherwise be reinvented per screen
(the prompt's own rationale). Tabs, sheets and toasts land with their first real consumer, against
the canvas that already specifies them.

**D-17 — Expo Router's web output stays a SPA; SEO is explicitly deferred.**
Public concert pages with link previews (prompt 21) will need static rendering or a server. Solving
it now would mean choosing an output mode for a page that does not exist. Recorded as R-7 and
flagged in `docs/architecture.md` so prompt 21 finds it rather than rediscovering it.

### Suggested implementation order

1. Expo app + TypeScript strict + Expo Router + lint/format; verify it runs on all three platforms
   **before** anything is built on top (AC-1.2 is the riskiest assumption in the branch).
2. Theme: tokens, provider, dark mode, fonts (D-13).
3. Base components + the four state components, against the canvas.
4. `npm run generate:api` and the committed `frontend/api/` (backend must be up once for the export).
5. `lib/api/`: client, `ApiError`, timeout, header seam; then the `useHealth` hook and the query
   provider.
6. The health screen, wired through the state components; verify the backend-down and `503` paths by
   actually stopping containers.
7. Jest + RNTL; the three health-screen states, the state components, the theme test.
8. CI: unstub the `frontend` job, add the artifact hand-off, and verify the staleness check fails on
   a deliberate contract change (AC-6.6).
9. Documentation pass: `frontend/README.md`, root `README.md`, `docs/architecture.md`, `/doc-check`.

## Out of Scope

- **Authentication UI and session handling** — prompt 04. This branch ships only the header seam
  (AC-7.6); it attaches nothing.
- **Concert screens** — the concert list, concert detail and the `ConcertCard` from
  `ConcertCard.dc.html` are prompt 07. The card is designed; it is not built here.
- **Playlist flows** — prompts 16 and 17.
- **Tabs, modals/bottom sheets, toasts, date inputs** — designed on the canvas, built with their
  first consumer (**D-16**).
- **App-store build configuration and submission** — EAS profiles, signing, store metadata.
- **An in-app theme toggle** — system preference only (AC-3.6).
- **Offline support, persistence and cache hydration** — no `AsyncStorage` persistence of the query
  cache.
- **Internationalization** — English only, per `CLAUDE.md`.
- **SEO, static rendering and link previews** — deferred to prompt 21 (**D-17**, R-7).
- **Analytics, crash reporting, telemetry.**
- **Any change to `backend/src/`.** If the OpenAPI document turns out to be insufficient for the
  client, that is a backend change and it is specified and reviewed as one — not patched from the
  frontend branch.
- **New environment variables.** `EXPO_PUBLIC_API_URL` and `EXPO_PUBLIC_ENV` already exist. If a new
  one proves necessary, it goes into **both** `docs/env-vars.md` and `frontend/.env.example` in the
  same branch, or `scripts/check-env-vars-drift.sh` fails.

## Dependencies

**Must be true before implementation begins**

| Dependency | Owner | Status |
|---|---|---|
| Prompt 01 merged — `GET /api/health`, OpenAPI document, `api:openapi:export` | `backend-engineer` | **Met** (`5c70f24`) |
| Prompt 02 merged — the design canvas in `docs/design/canvas/` | design canvas | **Met** (`694adad`) |
| `EXPO_PUBLIC_API_URL` / `EXPO_PUBLIC_ENV` declared in `frontend/.env.example` and `docs/env-vars.md` | Prompt 00 | **Met** |
| **D-3** — the frontend is not containerized — recorded in `docs/architecture.md` | Prompt 00 | **Met** |
| The exported OpenAPI document is valid input for `openapi-typescript` (API Platform emits **3.1**) | Upstream | **To verify at generation time** — see R-1 |
| Node 20 available locally and in CI | Developer / CI | **Met** (CI pins Node 20) |
| Backend stack runnable locally (`docker compose up -d`) for the first export and manual verification | Developer | Assumed |
| iOS and Android runtimes available to verify AC-1.2 (simulator/emulator or a device with Expo Go) | Developer | **To confirm** — see R-2 |
| Petrona / Manrope / Space Mono static weight files obtainable under OFL | Upstream (Google Fonts) | **To verify** (D-13) |

**Depended on by**

- **Prompt 04 (auth and accounts)** — builds login/registration on these components and attaches
  tokens at the AC-7.6 seam.
- **Prompt 07 (concert tracker UI)** — every screen consumes this theme, these components and this
  client.
- **Prompts 16, 17, 19** — the playlist flows depend in particular on `DegradedState` existing and
  reading as *mostly done*, not *broken*.

**Assumptions** *(labelled as assumptions, not verified facts)*

- The current Expo SDK supports Expo Router, `expo-font` and React 19 together without a pinned
  workaround. The implementer takes the latest stable SDK and records the version in
  `frontend/README.md`.
- `openapi-typescript` handles API Platform's 3.1 output (including the `503` response appended by
  `HealthOpenApiFactory`) without hand-editing. If not, R-1 applies.
- On a physical device, `EXPO_PUBLIC_API_URL` needs the machine's LAN IP instead of `localhost` —
  already noted in `frontend/.env.example`; this spec assumes no further networking work.
- `bin/console api:openapi:export` produces byte-stable output across runs on an unchanged codebase.
  If it does not (e.g. non-deterministic ordering), AC-6.5's diff check needs normalisation before
  comparison — R-3.

## Risks

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R-1 | **The generated client does not compile cleanly** — API Platform's OpenAPI 3.1 output produces types `openapi-typescript` renders awkwardly, and the temptation is to hand-fix `frontend/api/` | High — a hand-edited generated file destroys the whole contract mechanism (AC-6.4/6.5 would fail on the next run anyway) | Generate in step 4, before any calling code. `frontend/api/` is **never** hand-edited: if the output is wrong, the fix is in the backend's resource metadata (a backend change, out of scope here) or in the generator config. If neither works, **stop and report**. |
| R-2 | **iOS/Android cannot actually be verified** — no simulator, emulator or device available | Medium — AC-1.2 becomes an unverified claim, and "universal" is the whole premise | Confirm availability before starting. If a platform genuinely cannot be exercised, **report it and record which platforms were verified** in the PR — do not silently mark AC-1.2 met on web alone. |
| R-3 | **The OpenAPI export is not byte-stable**, so the AC-6.5 diff check fails on unrelated pushes | Medium — a flaky red build teaches people to ignore the check, which is worse than not having it | Verify stability during implementation by exporting twice. If unstable, normalise (sorted-key JSON) before comparing, and document why. |
| R-4 | **A dependency does not work under react-native-web** (or only on web) and the fix becomes a platform fork | Medium, compounding — every fork doubles the maintenance of that surface forever | D-15's gate: documented web support checked *before* adoption, and rendered on web during implementation. AC-1.8 forbids introducing a platform fork in this branch. |
| R-5 | **Tokens drift from the canvas** — a screen adds a "just this once" hex or a 15px gap | Medium — prompt 02's entire value is consistency by construction | AC-2.5 (no raw hex, no off-scale spacing) plus a lint rule where practical, and AC-2.7's rule that a token change starts on the canvas. |
| R-6 | **Degraded ends up looking like an error** — a warning triangle, an amber fill, a "failed" tone | High for the product's felt quality — "14 of 19 matched" is a *successful* run; if it reads as broken, the app feels broken whenever it works normally (prompt 02, `States.dc.html`) | AC-5.3 makes the distinction a tested assertion, and AC-5.6 makes these components the only sanctioned path. |
| R-7 | **Expo Router web output is a SPA**; prompt 21's public concert pages need SEO and link previews | Low now, Medium at prompt 21 — retrofitting rendering modes late is expensive | D-17: noted, not solved. Recorded in `docs/architecture.md` so prompt 21 starts from a known constraint. |
| R-8 | **CI runtime and coupling grow** — the frontend job now waits on the backend job's artifact | Low | Accepted; the coupling *is* the contract check. Revisit only if the pipeline becomes an obstacle. |
| R-9 | **Font bundling inflates the native bundle** or fonts fail to load on one platform, leaving fallback typography | Low–Medium | D-13 bundles only the weights the scale uses; the canvas fallback stacks stay in place so the app stays legible either way. |

## Documentation to update in this branch

Per `CLAUDE.md`'s mandatory documentation check (`/doc-check` before committing):

- **`frontend/README.md`** — **new**: stack and SDK version, the command set
  (`start`, `lint`, `typecheck`, `test`, `generate:api`), the token rule (AC-2.7), the
  "never call `fetch` directly" rule (AC-7.7), the state-component rule (AC-5.6), and the D-15
  dependency web-support gate with the approved list.
- **Root `README.md`** — the frontend is now a real app: how to start it, the local URL
  (<http://localhost:8081>), and the `generate:api` step after any API change.
- **`docs/architecture.md`** — the client-generation flow now exists in fact (the diagram at line 62
  already anticipates it); add the D-17 SPA/SEO note for prompt 21.
- **`docs/env-vars.md` and `frontend/.env.example`** — only if a new variable proves necessary, and
  both together, or the drift check fails.
- **`CLAUDE.md`** — no change expected; the API Contract section already describes this workflow.
  Update the Structure table only if the frontend layout diverges from what is described there.
- **No endpoint list in any README** — the generated OpenAPI document remains the single source of
  truth.

---

## Review

**This spec needs your approval before implementation begins.**

Please confirm in particular the eight decisions — each is a choice this spec makes on your behalf,
and each gets more expensive to reverse as prompts 04, 07, 16 and 17 build on it:

1. **D-10** — client committed **and** a CI staleness check, generated from the console export via a
   CI artifact (the stricter of the two options the prompt raised).
2. **D-11** — `openapi-fetch` bound to the generated `paths`, wrapped thinly, rather than a
   hand-rolled typed `fetch`.
3. **D-12** — TanStack Query v5, and **no** global client-state library yet.
4. **D-13** — fonts bundled with `expo-font` (OFL-licensed), not the web-only Google Fonts link.
5. **D-14** — tests stub `global.fetch`; no MSW.
6. **D-15** — a dependency web-support gate, and no platform-forked source files in this branch.
7. **D-16** — tabs, modals/sheets, toasts and date inputs deferred to their first real consumer;
   the four state components built now.
8. **D-17** — Expo Router web stays a SPA; SEO and link previews deferred to prompt 21.

Two items also need a factual confirmation from you before work starts: **R-2** (can iOS *and*
Android actually be exercised, or should the PR record web-only verification?) and the
**D-16 scope trim** (if prompt 04's auth screens will want tabs or a sheet, say so now and they move
into scope).
