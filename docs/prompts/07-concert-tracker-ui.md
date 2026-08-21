# 07 — Concert tracker UI

**Command:** `/feature concert-tracker-ui` · **Agent:** `frontend-engineer` · **Depends on:** 05, 06

## Goal
The designs from prompt 06, implemented and wired to the prompt-05 API: a user can add a concert with
several bands, see their upcoming and past concerts, open one, edit it and delete it — on web, iOS
and Android.

## Context
This completes the first genuinely useful version of Setlistify. After it, the app does something
worth opening even before any playlist feature exists.

Use the generated API client from `frontend/api/` — regenerate first if the spec moved. Do not
hand-write request or response types.

## Scope
- **Concert list**: upcoming and past sections, pull-to-refresh, pagination or infinite scroll, the
  designed empty state, and skeleton loading.
- **Add concert**: multi-band entry in billing order, date picker, progressive disclosure for venue,
  price and schedule. Optimistic creation with rollback on failure.
- **Concert detail**: lineup, date, venue, price, with the regions prompt 06 reserved for playlist,
  notes and sharing present but empty.
- **Edit and delete**, with confirmation on delete.
- Client-side validation mirroring the server's, plus correct rendering of RFC 7807 field errors.
- Navigation shell — tabs on phone, appropriate layout on desktop.
- Offline behaviour: cached lists remain readable; writes fail with a clear, recoverable message.
- Tests: list rendering (loading/empty/populated/error), the create flow, ownership-independent
  behaviour, and validation error display.

## Out of scope
- Setlist or playlist features — prompts 14 onward.
- Notes and reviews — prompt 20.
- Sharing — prompt 21.
- Band search against setlist.fm — prompt 09 adds it; free-text band entry is correct for now.

## Acceptance criteria
- [ ] The full create → list → detail → edit → delete loop works on all three platforms.
- [ ] A concert with several bands displays them in billing order everywhere.
- [ ] The empty state matches prompt 06 and gives a new user an obvious first action.
- [ ] Loading, error and offline states match the designs; nothing shows a raw error string.
- [ ] Server validation errors appear against the right fields.
- [ ] Dates display in the user's locale and are correct across timezones.
- [ ] No hand-written API types; `frontend/api/` is current against the spec.
- [ ] Tests green in CI.

## Risks & open questions
- Optimistic creation interacts awkwardly with server-side band deduplication — the server may return
  a different `Band` id than the client assumed. Reconcile on response rather than trusting the
  optimistic value.
- Date pickers differ across iOS, Android and web. Expect a per-platform branch and keep it isolated
  in one component.
- Decide whether the list is a single scroll with sections or two tabs. Sections keep past concerts
  discoverable; tabs scale better once someone has a hundred of them.
