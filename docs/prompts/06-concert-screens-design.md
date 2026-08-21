# 06 — Concert screens design

**Command:** `/design` · **Agent:** design canvas · **Depends on:** 02, 05

## Goal
Designed screens for the product's core loop — add a concert, browse your concerts, open one — using
the foundations from prompt 02 and matching the data the API from prompt 05 actually exposes.

## Context
Prompt 02 produced tokens and components; prompt 05 produced the real shape of the data. This prompt
turns both into concrete screens, so prompt 07 implements a design rather than inventing one.

Design for the real usage pattern: people add a concert on their phone, often in a hurry, sometimes
right after buying a ticket. Adding one should feel like jotting it down, not filling in a form.

## Scope
Artboards, at phone and desktop widths, for:
- **Concert list** — the home screen. Upcoming and past sections, the concert card from prompt 02,
  and a genuinely designed **empty state** for a brand-new user with nothing saved.
- **Add concert** — multi-band entry, date picker, and progressive disclosure so venue, price and
  schedule stay out of the way until wanted. Show the band-input pattern for adding several bands in
  billing order.
- **Concert detail** — the lineup, date and venue up top, with clearly reserved space for what later
  prompts add: the playlist and playback surface (19), notes and reviews (20), sharing (21). Design
  the page as it will eventually be, and show which regions are empty at this stage.
- **Edit and delete**, including delete confirmation.
- **Navigation shell** — tabs or drawer, on both phone and desktop.
- **Loading, error and offline** states for each screen.

## Out of scope
- Playlist generation screens — prompt 15.
- Authentication screens — those follow prompt 02's patterns directly and need no separate design.
- Backoffice screens — EasyAdmin supplies its own UI.

## Acceptance criteria
- [ ] Every screen exists at phone and desktop width.
- [ ] Only fields the prompt-05 API actually supports appear anywhere.
- [ ] The empty state for a new user is properly designed, not an afterthought — it is the first
      thing every user sees.
- [ ] Concert detail reserves and labels space for playlist, notes and sharing, so prompt 19–21 do
      not force a redesign.
- [ ] Loading, error and offline states are drawn for each screen.
- [ ] Everything is built from prompt 02's tokens and components; anything new is added back to the
      component inventory.
- [ ] Multi-band entry is demonstrated with at least three bands in billing order.

## Risks & open questions
- Multi-band entry is the hardest interaction here. A festival lineup can be long; adding one band
  must stay fast while adding eight stays possible.
- Decide how a past concert with no playlist differs visually from an upcoming one with no playlist.
  They mean quite different things.
- The date picker is a known cross-platform pain point on Expo. Design something that degrades
  acceptably to a native picker on each platform.
