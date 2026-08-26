---
name: spec-20-notes-reviews-decisions
description: Decisions D-227..D-247 proposed by the 2026-08-26 notes-and-reviews spec — 1-5 star rating, dual structured+snapshot highlight, past-only reviewing, note column migrated then dropped
metadata:
  type: project
---

`docs/specs/2026-08-26-notes-and-reviews.md` (backlog prompt 20) proposes **D-227 through D-247**.
Status as written: **draft, review requested**.

The three open questions the prompt raised were closed in the spec rather than deferred, per the
requester's instruction:

- **D-230** — rating is **1–5 integer stars, nullable, no half steps**. Ratified from the prompt-06
  canvas (`docs/design/canvas/screens/ConcertDetail.dc.html:151` already says "Star rating and a
  longer write-up will live here"), not re-litigated. Irreversible once used.
- **D-232** — the highlight is **both**: a nullable `Song` FK (`ON DELETE SET NULL`) *plus* an
  always-populated `highlightTitle` snapshot, which is the only value ever rendered. Mirrors
  `PlaylistTrack.sourceSong` + `sourceTitle`. FK-only would blank users' highlights when the nightly
  setlist refresh replaces `Song` rows.
- **D-234/D-235** — first writes **blocked server-side** on upcoming concerts (422 `REVIEW_NOT_YET`,
  from D-24's derived status) and de-emphasized client-side. Three exemptions: the migration, editing,
  and deleting are never blocked.

Other load-bearing decisions: **D-228** singleton sub-resource `GET/PUT/DELETE
/api/concerts/{concertId}/review` with `allowCreate` PUT (so "a second review edits the first" is
structural, not error handling); **D-238** no `visibility` column ships, so prompt 21 must decide
sharing rather than inherit a flag; **D-239/D-240** the migration copies → count-verifies → drops
`concerts.note` in one transaction, with `sourceNoteMigratedAt` provenance; **D-241**
`ConcertOutput.reviewSummary` via one LEFT JOIN; **D-242** the post-concert nudge is client-local
`AsyncStorage`, no push notifications; **D-243** the backoffice sees that a review exists, never the
body; **D-236** length limits counted in grapheme clusters.

**Why:** prompt 20 explicitly demanded a committed rating scale and highlight design, and the note
field being promoted holds real user text that has never been displayed (the detail screen renders
`ReservedSection` in its place, so `ConcertForm`'s note field was write-only).

**How to apply:** highest D-number after this spec is **D-247** — the next spec starts at **D-248**.
Open question deliberately left for the user: no documented Postgres backup/PITR policy, flagged as
needing its own infrastructure prompt. See [[spec-house-style]], [[spec-05-concert-decisions]],
[[spec-07-tracker-ui-decisions]].
