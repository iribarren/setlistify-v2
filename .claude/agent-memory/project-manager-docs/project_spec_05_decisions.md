---
name: spec-05-concert-decisions
description: Decisions D-24..D-31 proposed by the 2026-08-21 concert-domain-api spec — timezone model, band dedup, venue embeddable, 404-not-403 ownership, money as minor units
metadata:
  type: project
---

`docs/specs/2026-08-21-concert-domain-api.md` (backlog prompt 05) proposes **D-24 through D-31**.
Status as written: **draft, awaiting user approval** — do not treat as settled until confirmed.

- **D-24** — A concert is a **local calendar date + IANA timezone** (fixed offsets rejected);
  `doorsTime`/`startTime` are local `TIME`. Status is `upcoming` until the end of the concert's
  **own** local day, never the viewer's. Implemented as a derived `pastAfter` TIMESTAMPTZ column so
  the filter is one indexed comparison and no flag goes stale.
- **D-25** — Band dedup by stored `normalizedName` (unique index); normalization is a PHP service
  (trim/NFKD/lowercase/strip leading article/strip punctuation), deliberately **preferring false
  merges over false splits**. Prompt 09 replaces it with setlist.fm identity; prompt 08 owns
  merge/split tooling.
- **D-26** — `Venue` is a Doctrine **embeddable** serialized as a nested `venue` object, so prompt 24
  can promote it to an entity additively.
- **D-27** — Ownership = Doctrine **query extension (yields 404) + voter**. 404 not 403, so existence
  does not leak. This is the pattern every later user-scoped resource copies.
- **D-28** — Ticket price as **integer minor units + ISO 4217 code**, never a float; client formats
  with `Intl.NumberFormat`.
- **D-29** — `Concert` is never a writable entity resource; `ConcertInput`/`ConcertOutput` DTOs bound
  the payload (continues D-22).
- **D-30** — `note` is plain `TEXT`, no rendering contract, not sanitized server-side. Prompt 20 owns
  notes properly.
- **D-31** — Bounds set up front: lineup 1–30, band name 1–120, note 2000, page size ≤ 100, date
  within [1900, now+5y].

**Why:** Prompt 05 raised timezone, normalization and venue-modelling as open questions and expected
deliberate recommendations rather than deferrals.

**How to apply:** Highest D-number after this spec is **D-31** — continue from D-32. One question is
left open for the user by design: whether a read-only `GET /api/bands?q=` typeahead ships in this
branch (AC-8.7; spec default is no). See [[backlog-prompt-to-spec-flow]] and [[spec-04-auth-decisions]].
