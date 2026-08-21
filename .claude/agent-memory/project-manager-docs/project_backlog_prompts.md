---
name: backlog-prompt-to-spec-flow
description: How Setlistify feature specs originate — from a numbered brief in docs/prompts/, expanded (not copied) into docs/specs/YYYY-MM-DD-name.md
metadata:
  type: project
---

Feature specs in this project originate from a numbered source brief in `docs/prompts/` (00–26,
ordered backlog, each with Goal / Scope / Out of scope / Acceptance criteria / Risks). The user
points at one and expects it **expanded** into a full spec — user stories with per-story testable
acceptance criteria, explicit decisions with rationale, a risk table — not restated verbatim.

**Why:** The prompts are deliberately terse run-sheets; the spec is the reviewable artifact the user
approves before any branch is created (mandatory workflow in `CLAUDE.md`).

**How to apply:** When asked for a spec, read the matching `docs/prompts/NN-*.md` first, then the
docs it names, then write to `docs/specs/`. Resolve open questions the prompt raises into named
decisions with rationale rather than leaving them open, and surface them at the end for approval.
See [[spec-00-monorepo-decisions]].

**Decision IDs are project-global, not per-spec.** `docs/architecture.md` owns D-1..D-3; each new
spec continues the sequence (spec 01 introduced D-4..D-9, spec 03 D-10..D-17) so a decision can be
cited by ID across documents without ambiguity. Check the highest existing D-number before numbering
a new spec's decisions.

**Not every prompt produces a spec.** Prompt 02 (design system foundations) shipped as a design
canvas in `docs/design/canvas/` — nine `.dc.html` artboards plus `canvas.json` — with no file in
`docs/specs/`. It is the authoritative source for tokens (palette light/dark, type scale, 4px
spacing scale, radius, elevation, 44×44 touch targets, WCAG AA pairs, Lucide icons). When a later
spec depends on 02, read the artboards, not a spec.
