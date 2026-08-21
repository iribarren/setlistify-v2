# 03 — Frontend skeleton

**Command:** `/feature frontend-skeleton` · **Agent:** `frontend-engineer` · **Depends on:** 01, 02

## Goal
An Expo application that runs on web, iOS and Android from one codebase, styled with the tokens from
prompt 02, and talking to the backend through types **generated** from its OpenAPI spec.

## Context
The backend (01) serves `/api/health` and an OpenAPI document. The design foundations (02) exist as a
canvas. Read the API Contract section of `CLAUDE.md`: the generated client is not a convenience, it
is the mechanism that turns a breaking API change into a compile error.

## Scope
- Expo app in `frontend/`, TypeScript strict, Expo Router file-based routing.
- Design tokens transcribed from prompt 02 — colours (light + dark), typography, spacing, radii — as
  a typed theme, with system-preference dark mode support.
- The base component set from prompt 02's inventory: buttons, inputs, cards, list rows, plus the
  shared **loading, empty, degraded and error** state components. Building these now stops each later
  screen from inventing its own.
- OpenAPI client generation: an `npm run generate:api` script running `openapi-typescript` against the
  backend spec, output to `frontend/api/`, committed and regenerated whenever the spec changes.
- A typed fetch wrapper: base URL from `EXPO_PUBLIC_API_URL`, RFC 7807 error parsing, timeouts, and a
  single place where auth headers will later be attached.
- Server state via TanStack Query (or equivalent) — caching, retries, loading and error states.
- A home screen that calls `GET /api/health` and renders the result, proving the whole chain works.
- Jest + React Native Testing Library, with a test for the health screen covering loading, success and
  error.

## Out of scope
- Authentication UI — prompt 04.
- Concert screens — prompt 07.
- App-store build configuration and submission.

## Acceptance criteria
- [ ] `npx expo start` runs the app on web, iOS and Android from the same source.
- [ ] The health screen shows real backend data, and degrades visibly when the backend is down.
- [ ] `npm run generate:api` regenerates `frontend/api/` from the running backend's spec.
- [ ] No request or response type is declared by hand anywhere in the client.
- [ ] Dark mode follows the system setting and matches prompt 02's dark palette.
- [ ] TypeScript strict passes with no `any` in application code; tests are green in CI.
- [ ] Touch targets meet the minimum size from prompt 02 on a real phone viewport.

## Risks & open questions
- Some React Native libraries do not work under react-native-web. Check web support **before**
  adopting any dependency — this is the recurring tax of the universal-app approach.
- Decide how generated types stay fresh: committed and regenerated manually, or a CI check that fails
  when `frontend/api/` is stale against the spec. The second is stricter and worth it.
- Expo Router's web output is a SPA. If public concert pages later need SEO or link previews
  (relevant to prompt 21), that needs solving then — note it, do not solve it now.
