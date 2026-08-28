---
name: spec-house-style
description: How Setlistify spec documents in docs/specs/ are structured — the global D-nnn decision sequence, the header table, and the spike conventions
metadata:
  type: project
---

Setlistify specs in `docs/specs/` follow a house style that is NOT documented in `CLAUDE.md` and must
be matched when writing a new one.

- **Decisions are globally numbered `D-nnn` across every spec, never restarting.** Each spec declares
  its range in the header table and the next spec continues from the previous one's last number.
  As of 2026-08-23: spec 09 = D-56–D-70, spec 10 = D-71–D-88, spec 11 = D-89–D-105,
  spec 12 (song matching) = D-106–D-124, spec 13 (playlist pipeline) = D-125–D-144,
  spec 14 (playlist fast mode backend) = D-145–D-160,
  spec 16 (playlist fast mode UI) = D-161–D-181,
  the result-state-gaps fix (2026-08-24) = D-182–D-187,
  spec 17 (playlist normal mode, 2026-08-25) = D-188–D-209,
  spec 19 (concert page playback, 2026-08-26) = D-210–D-226,
  spec 20 (notes and reviews, 2026-08-26) = D-227–D-247,
  admin set-email-verified (2026-08-27, not a numbered prompt) = D-248–**D-253** (the earlier note
  saying D-256 was wrong — verified against the file),
  instant setlist refresh (2026-08-27, not a numbered prompt) = D-254–D-269 — next spec starts at
  **D-270**.
  **Check the highest existing D-number before writing** — e.g.
  `grep -rhoE "D-[0-9]{2,3}" docs/specs docs/architecture.md | sed 's/D-//' | sort -n -u | tail -3`
  (run it through `rtk proxy`, since the rtk grep filter strips the output).
- **A spec may amend an earlier spec.** Precedent:
  `2026-08-27-instant-setlist-refresh.md` narrows spec 09's D-65/D-67. The pattern is: an
  *Amendment to …* section near the top of the new spec, plus edits to the amended spec that only
  **add** — a `> Narrowed on DATE by D-nnn` blockquote under the affected decision, an
  `#### Amendment — DATE` block carrying the new decision entries verbatim, an *Amended by* header
  row, and a note appended to the affected Out-of-Scope row. Never delete or rewrite the original
  text: a decision record that edits its own history is not a record.
- **Header table** with rows: Spec ID · Backlog prompt · Command · Primary agent · Type ·
  Depends on · Implemented by · Decisions · Status.
- **Spike specs** (`/spec`, no branch/code) write their User Stories as properties of *the document*
  ("As the backend engineer implementing prompt 14, I want …"), with acceptance criteria that are
  checkable by reading the spec.
- Recurring sections beyond the mandatory ones: *Load-bearing rules this spec does not reverse*
  (a table mapping each `CLAUDE.md` rule to how the design honours it), *Existing groundwork this
  design builds on, not around*, *Recommendation Summary*, *Documentation to update (when prompt N
  implements this, not now)*, and a closing **Review requested** paragraph naming the most
  consequential decisions.
- **Open questions** live in a `Risks and Open Questions` section as a numbered list, each with a
  bolded **Recommendation:**. On approval they are rewritten in place as *Resolved on approval —
  DATE* with the reasoning kept, and the section renamed *Risks and Resolved Questions*.
- Tone: precise prose, heavy use of tables, real namespaces quoted (`App\Service\…`), rejected
  alternatives named with the reason. Arithmetic is worked explicitly rather than asserted.

**Why:** the specs are the project's decision record and prompts explicitly cite each other's D-numbers
(prompt 14 says it implements "whatever prompt 13 decided"), so a broken numbering sequence or a
missing section breaks cross-references.

**How to apply:** read the most recent spec in `docs/specs/` before writing a new one, and grep for
the highest `D-1nn` to find where to continue. See also [[backlog-prompts-drive-specs]].
