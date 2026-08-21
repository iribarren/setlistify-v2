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
web, which is why the storage adapter branches instead of this dependency being used directly).

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

## What's here

```
frontend/
├─ app/
│  ├─ _layout.tsx     theme/query/session providers, font loading, restore-gated loading state
│  ├─ index.tsx        health screen scaffold — unauthenticated, outside (auth)/(app)
│  ├─ verify-email.tsx  email verification confirm — unauthenticated, outside (auth)/(app)
│  ├─ (auth)/           login, register, forgot-password, reset-password — redirects in if already signed in
│  └─ (app)/            home — redirects to login if signed out
├─ api/               GENERATED — openapi-typescript output, committed, never hand-edited
├─ theme/             colors · typography · spacing · radius · elevation · ThemeProvider
├─ components/        Button, TextInput, Card, ListRow, Badge, Avatar
│  └─ state/          LoadingState, EmptyState, DegradedState, ErrorState
├─ lib/api/           openapi-fetch client, ApiError, timeout, header seam, query hooks
├─ lib/auth/          SessionProvider/useSession, token store, refresh coordinator, storage adapters
├─ scripts/           generate-api.mjs
└─ __tests__/
```

## Not built yet, on purpose (D-16)

Tabs, modals/bottom sheets, toasts and date inputs are specified on the canvas but land with their
first real consumer rather than being built speculatively here. Concert screens, playlist flows,
and an in-app theme toggle remain out of scope — see `docs/specs/2026-08-21-frontend-skeleton.md`,
"Out of Scope". Authentication UI shipped in `feature/auth-and-accounts`
(`docs/specs/2026-08-21-auth-and-accounts.md`).
