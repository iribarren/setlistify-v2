---
name: spec-16-fast-mode-ui-decisions
description: Spec 16 (playlist fast-mode UI, 2026-08-24) decisions D-161–D-181 — one route, Retry-After-driven polling, read-only report in fast mode
metadata:
  type: project
---

`docs/specs/2026-08-24-playlist-fast-mode-ui.md` — **D-161 – D-181**, written 2026-08-24, status
*draft, review requested*. Frontend-only; consumes spec 14's API and spec 15's canvas verbatim.

The consequential ones:

- **D-162 — one route, not four.** `app/(app)/concerts/[id]/playlist.tsx` renders progress, the four
  result variants and every degraded state from one state-driven view; splitting them would turn a
  server state change into a navigation event.
- **D-163 — polling stops when `Retry-After` is absent.** The client never enumerates the eleven job
  state names. Accepted trade: a backend header change silently changes client behaviour.
- **D-166 — `MOSTLY_MATCHED_FLOOR = 0.5`** separates `result_mostly` from `result_barely`. Prompt 15
  drew 14/19 and 4/19 without naming a threshold; this is a copy decision, one constant.
- **D-168 — no error colour on any partial or blocked view**, enforced by a rendered-tree test.
  `ErrorState` is reachable only from the pipeline's three genuine `failed` routes.
- **D-171 — the report is read-only in fast mode.** The row actions drawn on `Report.dc.html`
  ("Pick a version", …) are prompt 17's version selection, so `ResultMostly`'s CTA becomes
  "See what's missing" until then. Largest deliberate divergence from the artboards.
- **D-179 — no cancel UI**, though `POST …/cancel` exists: prompt 15 drew none.

**Why:** prompt 16 is where "degrades, does not fail" either survives contact with implementation or
does not; the spec's structure is built around making that testable rather than aspirational.

**How to apply:** prompt 17 (normal mode) restores the report row actions and the mode-chooser sheet;
prompt 19 fills the `reserved-playback` region D-176 leaves under the tracklist. See
[[spec-house-style]].
