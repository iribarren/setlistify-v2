---
name: spec-12-song-matching-decisions
description: Spike 12 (song matching) decisions D-106..D-124 — thesis, thresholds, version preference, caching store, prompt-18 calibration verdict
metadata:
  type: project
---

`docs/specs/2026-08-22-spike-song-matching.md` proposes **D-106..D-124** (drafted 2026-08-22, awaiting
approval). It is a SPIKE — recommendation only, implemented by prompt 14, re-calibrated by prompt 18.

The load-bearing calls, so a future session does not re-derive them:

- **Thesis:** simple + honest confidence beats clever. Settled by arithmetic, not taste — the scarce
  resource is provider calls (one YouTube search = 1% of the daily quota), matching CPU is <1% of a
  generation's wall time.
- **D-106** — song normalization is its own pipeline; `BandResolver::normalize()` is reused **only**
  for the artist side. Titles keep leading articles (band names strip them).
- **D-108** — parentheticals are extracted and classified (Version / FeaturedCredit /
  **TitleContinuation** as the default), never blindly stripped.
- **D-109/D-110** — weighted sum renormalized over *present* signals + a hard artist gate (cap 0.45).
  Bands: AUTO ≥ 0.80, CHOICE ≥ 0.55, else REJECT. Fast mode's CHOICE = include-and-flag; Normal
  mode's = the ranked choice. Thresholds live in `config/matching/profiles.yaml`, **not** in
  `ProviderSetting`/backoffice — deliberate departure, argued.
- **D-111/D-112** — studio is the default version; version-fit renormalizes away when only live
  candidates exist. Not user-configurable in MVP (Normal mode already is the choice affordance).
- **D-113** — covers are searched by the **original** artist, one search, attribution named in the
  report.
- **D-116** — non-song detection: `isTape` → curated whole-title lexicon (data, not code) → an
  advisory-only heuristic that never promotes a miss into a skip. Required precision 1.00.
- **D-118** — one algorithm, per-provider calibration. **Prompt 18 needs its own numbers, as
  configuration** (initial guess: autoAccept 0.85, weight moved into `artistAuthority`).
- **D-121** — resolutions cached in a Doctrine `TrackResolution` table with a Redis read-through;
  availability deliberately **not** cached (per-market, per-user).
- **D-123** — auto-accept precision (target ≥0.95 Spotify / ≥0.90 YouTube) is the primary metric;
  coverage may be traded down to protect it, never the reverse.

**Why:** the user's backlog treats prompts 12/13 as design-only spikes that prompts 14/17/18 must
follow; divergence has to be written back into the spec rather than drifted.

**How to apply:** when prompt 13, 14, 17 or 18 comes up, read this spec first — it already answers
the outcome vocabulary (`matched` / `matched_low_confidence` / `skipped` / `not_found` /
`region_restricted`), the budget arithmetic, and the medley segment-index schema consequence. Related:
[[spec-09-setlistfm-decisions]], [[spec-10-streaming-port-decisions]],
[[spec-11-provider-config-decisions]].
