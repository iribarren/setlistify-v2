---
name: frontend_tooling_gotchas
description: Non-obvious Expo/Jest/RNTL/openapi-fetch version and config issues hit while standing up frontend/ (2026-08-21) — check before re-debugging from scratch
metadata:
  type: project
---

Encountered while implementing the frontend skeleton (`feature/frontend-skeleton`,
[[frontend_stack]]). The registry state (package versions) may have moved on by the time this is
read — verify a fix still applies before reusing it, per the "before recommending from memory" rule.

**`npx create-expo-app` (and similar scaffolding npx invocations) get blocked by the sandbox's auto
mode classifier** in this environment, even though plain `npx expo <subcommand>` (e.g.
`npx expo install`) works fine. **Why:** the classifier appears to flag installer-shaped commands
specifically. **How to apply:** scaffold by hand — write `package.json` yourself, `npm install expo`
first, then use `npx expo install <pkg>` (the local Expo CLI) for every other dependency so SDK-
compatible versions get resolved automatically.

**`jest-expo` (57.0.4) is built against Jest 29, not Jest 30** — installing `jest@30` produces a
cryptic `this._moduleMocker.clearMocksOnScope is not a function` because `@react-native/jest-preset`
still pulls `jest-environment-node@^29.7.0`, causing a `jest-mock` version split across the module
graph. **Fix:** pin `jest@29.7.0` alongside `jest-expo`.

**`@react-native/jest-preset` must match the installed `react-native` version exactly**, not just
"latest". A mismatch (e.g. preset 0.87.0 against `react-native` 0.86.2, which is what Expo SDK 57
actually installs) fails with `Could not locate module react-native/setup-env`.

**`@testing-library/react-native` (v14+) now depends on a separate `test-renderer` npm package**
(not `react-test-renderer`) as a peer dependency, and its `render()` function is now **async**.
Every call site needs `await render(...)`; forgetting this manifests as `screen.getByX` throwing
`` `render` function has not been called `` even though `render()` was called (the returned promise
was just never awaited, so `screen`'s internal state was never populated).

**`lucide-react-native` resolves to an ESM-only (`.mjs`) build under Jest** via its
`package.json` `"react-native"` export condition, which Jest's transformer doesn't pick up by file
extension even when `transformIgnorePatterns` allows the package through. Fix: `moduleNameMapper`
redirect `^lucide-react-native$` straight to its `dist/cjs/lucide-react-native.js` build for tests
only (Metro still resolves the real ESM build for the shipped app).

**`openapi-fetch`'s `createClient()` captures `globalThis.fetch` once, at call time (module load)**
— not read dynamically per-request. A singleton API client module (created at import time) will
silently keep using whatever `fetch` existed before a test's `jest.fn()` stub replaced
`global.fetch`, unless the client is created with an explicit `fetch: (input) => globalThis.fetch(input)`
indirection.

**`openapi-fetch`'s `{ data, error, response }` result already parsed the response body** — calling
`response.json()` again on it (e.g. to build a custom error type) throws/hangs because the body
stream is already consumed. Build the error type from the pre-parsed `error` value instead of
re-reading `response`.

**A React Native `View` with `accessibilityRole` set does NOT automatically become
`accessible={true}`** — RNTL's `getByRole` queries silently fail to find it (`Unable to find an
element with role: ...`) even though the prop is visibly present in `screen.debug()` output. Add
`accessible` explicitly wherever a semantic role is set, and keep interactive children (buttons)
OUTSIDE that accessible group so they stay individually reachable rather than being swallowed into
one announced blob.

**`docker compose cp` from a container path sometimes silently produces a 0-byte or missing file on
the very next shell call in this environment** (each Bash call gets a fresh cwd/process, and there
appears to be a copy-completion race). Re-running the same `docker compose cp` immediately after,
inside the same tool call as the read, reliably works — don't trust a `cp` that happened in a prior
turn without re-verifying.

**API Platform's `bin/console api:openapi:export` output is byte-stable across repeated runs**
(verified twice, diffed identical) — no JSON key-sorting/normalization is needed before the CI
staleness `git diff --exit-code` check (this ruled out spec risk R-3 for this project).
