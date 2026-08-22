---
name: streaming_account_linking
description: Streaming port account-linking frontend (feature/streaming-port-and-account-linking) — the eslint alias-vs-relative platform-fork gotcha, dev env setup gaps hit verifying it live, and the module layout
metadata:
  type: project
---

Shipped in `feature/streaming-port-and-account-linking` (spec
`docs/specs/2026-08-22-streaming-port-and-account-linking.md`, US-1/US-2/US-3/US-5). Builds on
[[frontend_stack]] and reuses [[concert_tracker_ui]]'s platform-fork and optimistic-mutation
patterns. Backend half (12 commits) was already merged when this frontend half started.

**New gotcha: the eslint import resolver only follows the `.native`/`.web` platform-suffix
convention through a RELATIVE specifier, not the `@/` path alias.** `tsconfig.json`'s
`moduleSuffixes` makes `tsc` resolve `@/lib/streaming/linkAccount` fine, and Metro/Jest resolve it
fine too (haste config) — but `eslint-plugin-import`'s resolver throws `import/no-unresolved` on the
aliased form specifically. Every existing platform-forked import in this codebase (e.g.
`ConcertForm.tsx`'s `import { DateField } from "../DateField"`) was ALREADY relative for exactly
this reason, quietly — this is the first time it was traced to a cause rather than just copied.
**How to apply:** any new platform-forked module (alongside `lib/auth/storage.*`,
`components/DateField.*`, and now `lib/streaming/linkAccount.*`) must be imported by a relative path
from its consumer, never via `@/…`, or `npm run lint` fails with an unresolved-import error that
`tsc` won't catch.

**`ListRow`'s `testID` only reaches the DOM/native tree when `onPress` is set** (`components/
ListRow.tsx`: `if (!onPress) return content;` — the plain `View` branch never receives `testID`).
A composite row with several independent nested action buttons (no single "whole row" press target)
needs its own wrapping `<View testID={...}>` around `<ListRow>` for tests to find it — see
`components/streaming/StreamingAccountRow.tsx`.

**Local dev env drift**: this branch's backend commits declared several new env vars
(`SPOTIFY_ACCOUNTS_BASE_URL`, `SPOTIFY_API_BASE_URL`, `STREAMING_*`) in `backend/.env.example` and
`docs/env-vars.md`, but the actual running dev container's `backend/.env.local` (gitignored,
per-machine) hadn't been updated to match, and two Doctrine migrations for `streaming_accounts`
hadn't been run either — `POST /api/streaming/link` 500'd with `Environment variable not found` and
`GET /api/streaming/accounts` 500'd with `relation "streaming_accounts" does not exist` until both
were fixed (`docker compose up -d backend` to pick up an edited `env_file` — `docker compose
restart` does NOT re-read it — then `bin/console doctrine:migrations:migrate --no-interaction`).
**How to apply:** when a live-verifying a feature whose backend half was implemented in a prior
session/agent, diff `.env.example` against the running container's actual env
(`docker compose exec backend printenv`) and check `doctrine:migrations:status` BEFORE assuming a
500 is a frontend bug — it very often isn't.

**Module layout** (`frontend/lib/streaming/`): `linkAccountTypes.ts` (the shared `LinkAccount`
contract, same shape as `DateFieldTypes.ts`), `linkAccount.web.ts` (full-page redirect, promise never
resolves — the page navigates away) / `linkAccount.native.ts` (`expo-web-browser`'s
`openAuthSessionAsync` against a hardcoded `setlistify://account`, which must stay in sync with
`STREAMING_LINK_RETURN_URL_NATIVE`), `queries.ts` (list/start-link/resolve-link/unlink — unlink is
optimistic with snapshot/rollback, modeled on `useCreateConcert`'s onMutate/onError/onSuccess shape
since concert deletion itself is NOT optimistic, D-40), `errorMessage.ts` (`describeStreamingError` +
`providerDisplayName` + `revocationFollowUp`, the last one hardcoding that Spotify has no token
revocation endpoint, D-81). `components/streaming/` mirrors `components/concert/`'s shape
(`ConnectionsSection`, `StreamingAccountRow`, `DisconnectConfirmation`).

**The web ref-resolution flow reuses `verify-email.tsx`'s exact pattern**: `useLocalSearchParams()`
read once in a `useEffect`, `.then()/.catch()` setting state (NOT flagged by
`react-hooks/set-state-in-effect` since it's inside an async callback, not the effect body directly
— see [[concert_tracker_ui]] for the two hook-lint rules this project enforces), with
`eslint-disable-next-line react-hooks/exhaustive-deps` to keep it a one-shot per ref value.

**Verification limits actually hit**: no real Spotify developer app credentials existed in this
environment (`.env.local` had placeholder `SPOTIFY_CLIENT_ID`/`SECRET`) and no Chrome extension /
simulator was available, so the OAuth round trip could only be verified up to "the backend returns a
real `authorizationUrl` and the web app opens it" (confirmed via curl + Metro bundling cleanly) — not
an actual Spotify consent screen. The native code path was exercised through Jest (mocking
`expo-web-browser.openAuthSessionAsync`, which jest-expo's default `ios` platform resolution
naturally routes to `linkAccount.native.ts`), not a real device/simulator.
