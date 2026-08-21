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
(`application/problem+json`) error parsing into a typed `ApiError`, and the one documented header
seam (`apiClient.use(...)` in `lib/api/client.ts`) that prompt 04 (auth) attaches
`Authorization` to. Query hooks (e.g. `useHealth`) live here too, built on TanStack Query v5. A
screen or component that needs data calls a hook from `lib/api/`, never `fetch`.

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
`react-native-svg`.

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
├─ app/              Expo Router — _layout.tsx (theme/query providers, font loading), index.tsx (health screen scaffold)
├─ api/               GENERATED — openapi-typescript output, committed, never hand-edited
├─ theme/             colors · typography · spacing · radius · elevation · ThemeProvider
├─ components/        Button, TextInput, Card, ListRow, Badge, Avatar
│  └─ state/          LoadingState, EmptyState, DegradedState, ErrorState
├─ lib/api/           openapi-fetch client, ApiError, timeout, header seam, query hooks
├─ scripts/           generate-api.mjs
└─ __tests__/
```

## Not built yet, on purpose (D-16)

Tabs, modals/bottom sheets, toasts and date inputs are specified on the canvas but land with their
first real consumer (prompt 04 or 07) rather than being built speculatively here. Concert screens,
playlist flows, authentication UI, and an in-app theme toggle are all out of scope for this branch —
see `docs/specs/2026-08-21-frontend-skeleton.md`, "Out of Scope".
