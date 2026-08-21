# `frontend/components/state/`

Four components — `LoadingState`, `EmptyState`, `DegradedState`, `ErrorState` — matching
`docs/design/canvas/States.dc.html`.

## The rule

**These are the only sanctioned way to render loading, empty, degraded or error conditions
anywhere in the app.** A missing setlist, a partially matched playlist, a network failure — none of
it is invented per screen. If a screen needs one of these four outcomes and the existing props don't
fit, extend the component here; do not build an inline substitute.

## Why `DegradedState` exists separately from `ErrorState`

A playlist matched at 14 of 19 songs is a **successful** run of the product, not a failure. If it
renders with the same visual language as an error — a warning triangle, an amber/red tone, a
"failed" wording — the app reads as broken every time it works normally. `DegradedState` shows a
progress fraction and an info-blue fill; it never uses `ErrorState`'s icon or color. This is
asserted by `frontend/__tests__/state.test.tsx`, not left to reviewer memory (AC-5.3).

## Props

Every component takes a `title`, a `body`, and an optional `action` (`{ label, onPress }`) — later
screens supply copy, not layout. `ErrorState`'s `action` is required: an error always offers a retry
(AC-5.4). `LoadingState` sets `accessibilityLiveRegion="polite"` so assistive tech announces it
without polling (AC-5.5).
