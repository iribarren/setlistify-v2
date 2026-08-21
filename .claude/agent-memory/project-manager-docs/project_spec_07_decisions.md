---
name: spec-07-tracker-ui-decisions
description: Decisions D-32..D-41 proposed by the 2026-08-21 concert-tracker-ui spec — list sections not tabs, optimistic-create reconciliation, single DateField platform fork, no offline write queue
metadata:
  type: project
---

`docs/specs/2026-08-21-concert-tracker-ui.md` (backlog prompt 07) proposes **D-32 through D-41**.
Status as written: **draft, awaiting user approval**.

- **D-32** — Concert list is **one scroll with Upcoming/Past sections**, not two tabs (matches the
  prompt-06 canvas `Main.dc.html`); implemented as two independent paginated queries so a later
  switch to tabs is a layout change only. Answers the prompt's open question.
- **D-33** — Optimistic create is **replaced wholesale** by the 201 response, never merged — because
  server-side band dedup (D-25) can return a different `Band` id/name.
- **D-34** — Exactly **one platform fork** in the branch: `DateField.native.tsx` / `DateField.web.tsx`.
  No screen imports `Platform` (honours spec 03's AC-1.8).
- **D-35** — Device IANA zone via `Intl` as the create default; concerts render in **their own**
  timezone, never converted to the viewer's (D-24).
- **D-36** — Client validation mirrors D-31 bounds but is **advisory**; server is authoritative.
- **D-37** — Offline: cached reads yes, **no write queue / background sync** (deliberately deferred).
- **D-38** — `Intl.NumberFormat` from minor units (D-28); all money/date conversion in one mapping module.
- **D-39** — Phone vs desktop layout = **width breakpoint in one layout file**, not `Platform.OS`.
- **D-40** — Delete is permanent, confirmation says so (API hard-deletes, spec 05 AC-6.5). No undo.
- **D-41** — Infinite scroll over Hydra `view.next`, page size 20.

**Why:** Prompt 07 raised sections-vs-tabs, optimistic/dedup reconciliation and the cross-platform
date picker as open questions and expected recommendations, not deferrals.

**How to apply:** Highest D-number after this spec is **D-41** — continue from D-42. One question is
left open for the user by design: whether offline *writes* must work at the venue (D-37 says no; it
would be its own spec). See [[backlog-prompt-to-spec-flow]] and [[spec-05-concert-decisions]].

**Prompt 06 shipped as a design canvas, not a spec** — `docs/design/canvas/screens/` (commit
`a75b2a7`): Main, EmptyState, AddConcert, ConcertDetail, EditDelete, NavShell, States,
NewComponents. Like prompt 02, read the artboards, not `docs/specs/`.
