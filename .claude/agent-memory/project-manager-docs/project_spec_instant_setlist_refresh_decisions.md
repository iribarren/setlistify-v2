---
name: spec-instant-setlist-refresh-decisions
description: D-254..D-280 for the 2026-08-27 instant setlist refresh spec; it formally amends spec 09's D-65/D-67/D-57 and supersedes its own D-268
metadata:
  type: project
---

`docs/specs/2026-08-27-instant-setlist-refresh.md` (Draft, review requested 2026-08-27) proposes
**D-254 – D-269** and is the **first spec in the project to formally amend another one**:
`docs/specs/2026-08-22-setlistfm-integration.md` now carries an *Amendment — 2026-08-27* section with
D-254/D-255 written into it, scope notes on D-65 and D-67, an "Amended by" header row, and an updated
Out-of-Scope row. **Nothing was deleted from spec 09** — the amendment pattern is *add a scope note,
never edit history*. Reuse this pattern if another spec ever has to reverse a shipped decision.

Load-bearing choices, so they are not re-litigated:

- Entitled-only on-demand refresh, drawing from the **same** `SetlistFmBudget` pool — no quota
  carve-out, no priority lane (D-254). Backoffice still gets no "refresh now" button, permanently
  (D-255).
- Async over Messenger, not synchronous (D-256) — worst case ~20s of held FrankenPHP worker otherwise.
- Entitlement is a nullable `User.instantRefreshGrantedAt` column, **not** a mutated `User.roles`
  (D-257): "roles are written exactly once at registration" is treated as a security property.
- Three throttles in front of the budget gate: per-band cooldown, per-user daily cap, application
  budget reserve (D-259). All fail-closed.
- Every refusal is `429` + `Retry-After` + typed `refusedReason`; `FreshnessEnvelope`'s enum is
  embedded, never extended (D-260, D-261).
- **User-side disambiguation is IN scope** (D-270 – D-280, added 2026-08-27 when the user answered
  Open Question 1 "yes" against the spec's own recommendation). **D-268 — "ambiguity is reported,
  never resolved by the user" — is superseded but kept in place with a note**: the spec applies its
  own add-a-note-never-delete amendment rule to itself, which is now the house pattern for reversing a
  decision *within* a document as well as across documents. The safeguards that made the widening
  acceptable: pick from a server-produced candidate set only, never free text (D-271); write only into
  a band with a null MBID, never an overwrite, so D-56 survives (D-270); once per band (D-276); any
  candidate is pickable, **not** exact-normalized-name only, because the 0-exact-matches shape is the
  Boikot case (D-272); audited as `choose_band_mbid`, distinct from the operator's `correct_band_mbid`
  (D-274). All three open questions are now closed — nothing in the spec is undecided.
- It **pre-dates and constrains** prompt 22 (entitlement/quota seam) rather than depending on it
  (D-267): prompt 22 must absorb the column, replace the voter body, and supersede the per-user cap.

**Why:** the amendment is the unusual part — a future reader of spec 09 must not conclude on-demand
refresh is still forbidden, and a future reader of this spec must not conclude the budget mechanics
were relaxed.

**How to apply:** cite D-254/D-255 whenever on-demand setlist.fm spending comes up; treat spec 09's
D-56/D-59/D-61/D-63 as fully intact. See [[spec-09-setlistfm-decisions]] and
[[spec-house-style]].
