# 05 — Concert domain and API

**Command:** `/feature concert-domain-api` · **Agent:** `backend-engineer` · **Depends on:** 04

## Goal
The core domain: a user records a concert — one or more bands, a date, and optionally a venue, ticket
price and schedule — and can list, filter, update and delete their own concerts through the API.

## Context
This is the product's foundation. Everything downstream (setlists, playlists, notes, sharing) hangs
off `Concert`. Read the domain glossary in `CLAUDE.md` and the data model in
`docs/architecture.md` §10 before modelling.

The important modelling decision: a concert has **many** bands (festivals, support acts), and a band
is shared across concerts and across users. `Band` is therefore its own entity, not a string on
`Concert` — prompt 09 will attach setlist.fm identifiers to it, and prompt 24 may attach photos and
metadata.

## Scope
- `Concert`: owner, date (with timezone care), optional venue name/city/country, optional ticket
  price + currency, optional doors/start time, free-text note field, timestamps.
- `Band`: name, normalized name for matching, optional external identifiers (nullable now,
  populated by prompt 09), timestamps.
- `ConcertBand` join carrying billing order (headliner first) so a lineup keeps its shape.
- Derived `upcoming` / `past` status computed from the date — **not a stored flag that goes stale**.
- CRUD as API Platform resources, with ownership enforced by a Doctrine filter or voter so a user can
  never read or write another user's concert.
- List endpoint: pagination, filter by upcoming/past, sort by date, search by band name.
- Validation: date sanity, non-negative price, ISO currency, at least one band, lineup size bound.
- Band deduplication on create — "Radiohead" typed by two users is one `Band` row.
- Tests: ownership isolation, validation, upcoming/past boundary behaviour, band dedup.

## Out of scope
- Any setlist.fm lookup or band verification — prompt 09.
- Playlists — prompt 14 onward.
- Notes and reviews as a first-class feature — prompt 20 (a plain note field here is enough).
- UI — prompt 07.

## Acceptance criteria
- [ ] A user can create a concert with multiple bands, in billing order, and read it back intact.
- [ ] A user cannot read, update or delete another user's concert — 404, not 403, so existence does
      not leak. Covered by test.
- [ ] Listing supports pagination, upcoming/past filtering and date sorting.
- [ ] A concert dated today behaves correctly at the upcoming/past boundary, in the user's timezone.
- [ ] Creating a concert with an existing band name reuses the `Band` row rather than duplicating it.
- [ ] Deleting a concert leaves shared `Band` rows intact.
- [ ] Every endpoint appears correctly in the OpenAPI spec.
- [ ] Validation failures return RFC 7807 with per-field detail.

## Risks & open questions
- **Timezones**: a concert happens in a venue's local time, but the user may be anywhere. Store the
  date with an explicit timezone and decide deliberately which one drives "upcoming vs past". Getting
  this wrong is subtle and irritating.
- Band name normalization for dedup is genuinely hard ("The Beatles" vs "Beatles", "Sigur Rós" vs
  "Sigur Ros", "AC/DC"). Keep it simple here — case/diacritic/article-insensitive — and let prompt 09
  improve it once setlist.fm can confirm identity.
- Venue is free text for now. Prompt 24 may promote it to an entity; model it so that is not painful.
