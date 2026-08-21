# 24 — SPIKE: rich band and song metadata

**Command:** `/spec spike-rich-metadata` · **Agent:** `backend-engineer` · **Depends on:** 09, 12, 18

## Goal
An assessment of whether enriching bands, songs and venues from external metadata sources is worth
doing — for visual quality, and more interestingly, for **match accuracy**.

**This prompt produces a document, not an implementation.** Use `/spec`.

## Context
Setlistify currently knows a band's name and its setlist.fm identifier. That is enough to work, but it
makes for plain screens: no band photos, no album art beyond what a provider embed supplies, no
canonical song titles.

The more valuable angle is not cosmetic. Prompt 18 found that YouTube's catalog noise is the dominant
difficulty in matching. **Canonical song titles and identifiers from a proper music database could cut
that noise materially** — which makes this a matching-quality investigation wearing a visual-polish
costume. Frame it that way.

## Scope of the investigation
- **Evaluate the candidate sources**: MusicBrainz (open, canonical, already the source of the MBIDs
  setlist.fm uses — likely the strongest fit), Last.fm, Discogs, Wikidata/Wikipedia, and the
  providers' own metadata. For each: licensing and attribution requirements, **commercial-use terms**
  (the same trap documented throughout `docs/external-apis.md`), rate limits, coverage and data
  quality.
- **Quantify the matching benefit.** Take the fixture set from prompt 12 and estimate how many
  currently-poor matches canonical titles would fix. If the answer is "few", that is the finding and
  the rest is optional polish.
- **What is worth fetching**: band photos, band biography, album art, canonical song titles, song
  identifiers, release dates, venue details and geocoding.
- **Storage and refresh**: what is cached, for how long, and how images are stored and served without
  hotlinking someone else's bandwidth.
- **Attribution obligations** — MusicBrainz, Last.fm and Discogs each require it, in different forms.
  Specify exactly what must appear in the UI.
- **Cost and quota** of each source at realistic volume.
- **Failure behaviour**: metadata is decoration, so its absence must degrade invisibly. Confirm this is
  achievable for every proposed use.
- **Recommendation**: which sources, for which fields, in what priority — or none.

## Out of scope
- Implementation.
- Changing the matching algorithm — that would be a follow-up prompt if this spike justifies it.
- User-uploaded images — prompt 25.

## Acceptance criteria
- [ ] A written assessment exists in `docs/specs/`, recommending specific sources or recommending none.
- [ ] **Commercial-use terms are checked for every source** and recorded, with the same rigour as
      `docs/external-apis.md` applies elsewhere.
- [ ] Attribution requirements are stated precisely enough to implement.
- [ ] The matching-accuracy benefit is **quantified against prompt 12's fixture set**, not asserted.
- [ ] Rate limits and costs are stated at realistic volume.
- [ ] Image storage and serving is addressed, including bandwidth and hotlinking.
- [ ] Graceful degradation when metadata is missing is confirmed for every proposed use.
- [ ] The recommendation is prioritized, so a partial implementation is possible.

## Risks & open questions
- **Check commercial terms even though monetization is deferred.** Building on a source that cannot be
  used commercially would quietly foreclose an option later — precisely the mistake this project
  avoided with Spotify by researching early.
- MusicBrainz is the natural fit since setlist.fm already uses MBIDs, but its rate limit is strict
  (roughly 1 request/second) and it expects a proper user-agent. At Setlistify's scale that is likely
  fine — confirm rather than assume.
- Band photos have their own licensing problem: MusicBrainz does not host images, and other sources
  attach conditions. This may be the least tractable field of the lot, despite being the most visible.
- Beware scope creep into a general music-metadata layer. The question is narrow: does this improve
  matching, and does it make the app look meaningfully better?
