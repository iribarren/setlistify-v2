# D — Matching-quality harness and the fixture gap

**Branch:** prompt 14's branch (`feature/playlist-fast-mode-backend`), or a follow-up off `master`
if that has merged · **Priority:** Medium

Unfinished **prompt 14** scope. Nothing exists: no `@group matching-quality`, no fixture corpus, no
manifest, no checksum freeze. Prompt 14 calls this *"the only thing standing between a matching tweak
and a silent regression across every future generation."*

**This prompt cannot fully finish** — arming the gate needs live Spotify credentials for a capture
pass and a human labelling pass. The prompt is written so the whole mechanism gets built and the
data gap is made loud rather than papered over. It ends by handing you a capture checklist.

```
Continue prompt 14 on feature/playlist-fast-mode-backend (or a follow-up branch — check).

Read `docs/specs/2026-08-22-spike-song-matching.md` §9 and D-122/D-123.

The matching-quality gate does not exist — no `@group matching-quality`, no fixture corpus, no
manifest, no checksum freeze. Prompt 14 calls this "the only thing standing between a matching
tweak and a silent regression across every future generation".

Build the whole mechanism for real:
- The `@group matching-quality` harness and its manifest format.
- Metric computation: auto-accept precision (primary), non-song precision, silent-error rate.
- The threshold gate: >= 0.95 auto-accept precision (Spotify), 1.00 non-song precision,
  <= 0.03 silent error. Build-failing when breached.
- The manifest-checksum test enforcing the fixture freeze.
- A fixture manifest covering every case you can label honestly from setlist text ALONE, with no
  live catalog lookup: the non-song entries, the medley splits, the diacritic/ligature
  normalization cases, the leading-article cases.

DO NOT fabricate captured Spotify search responses or invent ground-truth track IDs. There are no
live Spotify credentials and no human labeller available — faking them hollows out the exact gate
this exists to provide. Instead, the catalog-dependent portion must SKIP WITH AN EXPLICIT REASON,
never pass silently, and must fail loudly the moment real fixtures are dropped in and a threshold
is missed.

Record the gap in the spec and in the test's docblock, naming precisely what a human must supply
to arm it: Spotify credentials for a capture pass, the eight real setlists from §9, and the
~200-entry labelling pass.

Then output for me, as a checklist, exactly what I need to capture and label.
```

## Follow-up, once the harness exists

Arming the gate closes **D-122**, which spec 12 leaves open: the thresholds
(`autoAccept 0.80 / choice 0.55`) and the 60/40 title blend are explicitly an initial calibration,
and spec 12 §3 says prompt 14 must run the harness and record the tuned values back into the
document. Bump `matching.algorithm_version` in `backend/config/matching/profiles.yaml` with any
change, or cached resolutions will mix two calibrations.
