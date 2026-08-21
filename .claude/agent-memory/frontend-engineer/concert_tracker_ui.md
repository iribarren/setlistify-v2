---
name: concert_tracker_ui
description: Concert tracker UI feature (feature/concert-tracker-ui) — nav shell rename, ConcertForm reconciliation quirks, testing gotchas hit while building it
metadata:
  type: project
---

Shipped in `feature/concert-tracker-ui` (spec `docs/specs/2026-08-21-concert-tracker-ui.md`,
`docs/architecture.md` D-32–D-41). Builds on [[frontend_stack]] and [[frontend_tooling_gotchas]].

**Concerts (not Account) is the app's real home now.** `app/(app)/home.tsx` was renamed to
`account.tsx`; `(auth)/_layout.tsx` and `(app)/index.tsx` both redirect to `/concerts`. Any future
work assuming `/home` exists is stale — check `frontend/README.md`'s route tree first.

**React Compiler's eslint-plugin-react-hooks here forbids BOTH `setState` directly inside a
`useEffect` body (`react-hooks/set-state-in-effect`) AND reading/writing a ref during render
(`react-hooks/refs`)** — together they rule out the common "track previous prop in a ref, diff it
during render" pattern entirely. The lint-clean way to "adjust state when a prop changes" is
React's own documented pattern using a **second piece of `useState`** to hold the previous prop
value, not a `useRef`:
```ts
const [previous, setPrevious] = useState(propValue);
if (previous !== propValue) {
  setPrevious(propValue);
  setLocalState(propValue);
}
```
This calls `setState` conditionally in the render body itself (not in an effect), which React
explicitly sanctions and the lint config does not flag. See `components/concert/ConcertForm.tsx`'s
`previousViolations`/`serverErrors` sync for the concrete example — worth reusing verbatim for any
future "sync a form's local error state from a parent prop after a fresh submit" need.

**Corollary: don't cache client-side validation errors in `useState` set only at submit time** —
recompute them live via `useMemo(() => validate(values), [values])` instead. A `useState` snapshot
taken at submit time goes stale the moment the user fixes the flagged field, because nothing
re-runs the validator on every keystroke; the fix silently leaves the old error on screen even
though the underlying value is now valid. Hit this building `ConcertForm`'s AC-8.6 test
("fixing the flagged field clears its error") — first attempt (`setClientErrors` in `handleSubmit`)
failed exactly this way.

**Testing an `openapi-fetch`-backed pending/rollback mutation: never resolve a single shared
`Promise<Response>` for two concurrent requests.** A `Response`'s body can only be read once,
so if `useConcertsSection("upcoming")` and `useConcertsSection("past")` both await the SAME
resolved `fetch()` promise, only the first consumer's `.json()` succeeds and the second hangs/
throws. Fix: have the mock `fetch` `await` a shared *gate* `Promise<void>`, then construct and
return a FRESH `Response` per call:
```ts
let releaseGate!: () => void;
const gate = new Promise<void>((resolve) => { releaseGate = resolve; });
global.fetch = jest.fn(async () => { await gate; return jsonResponse(200, body); });
```
Cost about 20 minutes of a stuck "still shows the skeleton" test failure to track down.

**Dynamic `await import(...)` inside a test body throws `A dynamic import callback was invoked
without --experimental-vm-modules`** under this project's Jest/Babel config — just import
`fireEvent` (or whatever) at the top of the file like everything else; there's no need to defer it.

**A variable referenced inside `jest.mock(...)`'s factory must be named `mock*` (case-insensitive)**
or Jest's out-of-scope-variable guard throws at transform time — even a `let` declared above the
`jest.mock()` call in the same file. Hit this wiring up `useLocalSearchParams` mocks per-test
(`concert-detail-404.test.tsx`'s `mockCurrentId`).

**`useWindowDimensions()`-driven layout (D-39's phone/desktop breakpoint,
`app/(app)/_layout.tsx`) needs no special test harness** — jest-expo's default window size just
picks one branch consistently; tests that stub `@/components/nav` don't care which.
