# 20 — Notes and reviews

**Command:** `/feature notes-and-reviews` · **Agent:** `backend-engineer` + `frontend-engineer` · **Depends on:** 07, 19

## Goal
After a show, the user can write down what it was like — a note, a rating, the highlight of the
night — on the concert page, alongside the playlist of what was played.

## Context
This is what turns Setlistify from a utility into something worth keeping. The playlist is the
artefact; the note is the memory. Together the concert page becomes a gig diary.

Prompt 05 left a plain note field on `Concert`. This prompt promotes it into a proper feature with
structure, and migrates any existing data rather than abandoning it.

Design for the actual moment of use: someone writing on their phone, late, tired, possibly on a train
home. Short and easy beats comprehensive.

## Scope
- `ConcertReview` entity: concert, author, rating, free-text notes, optional highlight/best-song
  reference into the setlist, timestamps. One review per user per concert.
- Migration from prompt 05's free-text note field — existing content preserved, not dropped.
- CRUD endpoints, ownership-enforced exactly as prompt 05's concerts are.
- **Reviews are private by default.** Anything else is a deliberate decision belonging to prompt 21,
  not an assumption made here.
- Availability rules: reviewing is offered for past concerts. Decide and document whether it is
  blocked or merely de-emphasized for upcoming ones.
- Frontend: a review section on the concert page — write, edit and delete — following prompt 06's
  reserved region and prompt 02's components.
- A prompt to review after a concert's date passes, unobtrusive enough not to nag.
- Notes visible in the concert list as an indicator (reviewed / not reviewed), so the diary is
  browsable.
- Text handling: sensible length limits, correct storage and rendering of emoji and multi-byte text,
  and output escaping.
- Tests: CRUD, ownership isolation, the migration, one-review-per-concert enforcement, and text
  handling including emoji.

## Out of scope
- Sharing a review publicly — prompt 21.
- Photos or video — prompt 25.
- Public profiles, following, or any social graph.
- Comments from other users.

## Acceptance criteria
- [ ] A user writes, edits and deletes a review on a past concert, on all three platforms.
- [ ] A user cannot read or write another user's review — covered by test.
- [ ] Existing note content from prompt 05 survives the migration intact.
- [ ] Only one review per user per concert; attempting a second edits the first.
- [ ] Reviews are private by default, with no endpoint exposing another user's.
- [ ] Emoji and multi-byte text round-trip correctly on all platforms.
- [ ] Text is escaped on output; no injection is possible through review content.
- [ ] The concert list indicates which concerts have been reviewed.
- [ ] The post-concert prompt appears at a sensible time and is dismissible.

## Risks & open questions
- Decide whether the highlight/best-song field references the setlist (structured, enables future
  aggregation like "your most-seen song") or is free text (simpler, and works when no setlist was
  found). The structured version is more valuable but must degrade when there is no setlist.
- Rating scale: stars, a 1–10, or a simple good/great/unforgettable. Pick one and commit; changing a
  scale after people have used it loses meaning.
- Reviews are personal writing. Make sure the GDPR erasure path from prompt 08 removes them properly,
  and that they are covered by whatever backup policy exists.
