# Investigation: setlist.fm integration and the Boikot case

Date: 2026-08-27
Reported by: heleritz@gmail.com
Symptom: concert for band **Boikot** (2026-10-02) — playlist generation reports no setlist found,
even though the band's setlists exist on setlist.fm.

## How the setlist.fm integration works

### The single door: `SetlistGateway`

`backend/src/Service/Setlist/SetlistGateway.php` is the only class in the codebase allowed to reach
setlist.fm (enforced by the static test `SetlistGatewayIsOnlyDoorTest`, D-58). It exposes three
methods — `searchArtist`, `fetchArtistSetlistsPage`, `fetchSetlistDetail` — all delegating to
`SetlistCache`.

### Three-tier cache (`SetlistCache`)

1. **Redis** — short TTL (`SETLISTFM_CACHE_TTL`, default 300s), a session-scoped accelerator only.
2. **Postgres `setlist_cache`** — the durable tier, keyed by `endpoint:md5(params)`. Freshness is
   per-endpoint:
   - `fetchArtistSearch` and `fetchArtistSetlistsPage` page 1: stale after **1 day** (volatile —
     new bands/shows can appear).
   - `fetchArtistSetlistsPage` page ≥2, and `fetchSetlistDetail`: **never** go stale once fetched
     (a past setlist doesn't change, D-59/D-60/AC-4.5).
3. **Live setlist.fm call** — only on a Postgres miss or stale entry, serialized per cache key with
   a `symfony/lock` so concurrent requests don't double-spend the budget.

### The shared budget (`SetlistFmBudget`)

Every live call passes through a single gate: a per-second Redis token bucket
(`SETLISTFM_RATE_PER_SECOND`, default 2), a daily counter (`SETLISTFM_DAILY_BUDGET`, default 1440 —
shared across the whole app, not per user), and a circuit breaker that opens for 60s after 5
consecutive transient failures. It fails **closed**: any Redis error refuses the request rather than
falling back to unlimited access. A web request waits at most `SETLISTFM_TOKEN_WAIT` (default 1s)
for a token before giving up — by design (D-62), user-facing requests never queue on the limiter.

### Band identity resolution (`BandIdentityResolver`)

A band's setlist.fm identity is a MusicBrainz ID (MBID), resolved once and never re-derived (D-56).
`ensureResolved(Band $band)`:

- If the band is already `resolved`, `ambiguous`, or `no_presence` — **return immediately**. It does
  not re-search. Comment in the code: *"re-checking an already ambiguous/no_presence band is the
  nightly job's business... never triggered by a plain read"* (AC-5.4).
- Only if `unresolved` does it search setlist.fm by name and classify the result:
  - 0 candidates → `no_presence`.
  - exactly 1 exact-normalized-name match → `resolved`.
  - 0 exact matches among several, or >1 exact match → `ambiguous`.

`recheckNoPresenceIfDue()` is the *only* automatic re-check, and it only applies to `no_presence`
bands, only once every 30 days, and only from the nightly job. **An `ambiguous` band is never
automatically re-resolved by anything.** The only way out is a manual MBID correction in the
backoffice (`BandCrudController::performCorrectMbid`, D-67) — there is no "resync" button anywhere
in the product, by explicit design (D-67: *"a one-click budget spend on the most dangerous resource
in the product, and the nightly job already covers the need"*).

### Playlist generation (`PlaylistPipeline` → `SetlistSelectionStage`)

For each band on the lineup: check already-cached setlists; if none, resolve identity and fetch
**one** live index page (`GENERATION_SETLIST_PAGES=1`). `SubstantialSetlistSelector` then picks the
band's most "substantial" recent setlist from up to 20 candidates, subject only to a 24-month
recency floor in the past.

**Important: the concert's own date is never used to filter or match setlists.** It's surfaced to
the UI as an `isSameNight` flag for the user's benefit in Normal mode, but generation doesn't require
the chosen setlist to be near the concert date. A future concert date, by itself, is not a reason
generation would fail.

### Nightly refresh (`app:setlist:refresh`)

A deployment-level cron job (no in-app scheduler). Each run: pulls every band with an upcoming
concert (ordered nearest-first) plus bands whose concert ended within 7 days, resolves identity for
`unresolved` bands, rechecks due `no_presence` bands, and backfills setlist pages — capped at 25% of
the daily budget by default. Boikot's concert (2026-10-02), once created, is in scope for every
nightly run between now and the show.

## Why Boikot specifically isn't showing a setlist

Ruling out the date (see above — it isn't the mechanism), the realistic causes, most likely first:

1. **Boikot's resolution state is stuck at `ambiguous` or `no_presence`.** A name-normalization
   mismatch (diacritics, or another band sharing the name "Boikot" on setlist.fm) at the first
   lookup would produce this, and — critically — nothing retries automatically once a band is
   `ambiguous`. This is the most likely explanation given the user is confident the band exists on
   setlist.fm: a false negative from name matching, not missing data upstream.
2. **The first live lookup degraded silently** (budget exhausted / rate limited / circuit breaker
   open at that moment). The band would remain `unresolved`, and the next attempt (a regenerate, or
   the nightly job) should succeed — this self-heals within a day.
3. **Only page 1 of setlists was ever fetched on-demand**, and Boikot's substantial recent setlist
   isn't among the most recent 20 results, with the nightly job not yet having backfilled further
   pages.

## How to confirm which, right now

Check Boikot's row via the backoffice: `setlistfmResolutionState` and `setlistfmMbid`. If it's
`ambiguous` or `no_presence`, that's the answer — the fix today is the backoffice's manual MBID
correction (D-67). The playlist generation job's report also carries a `noSetlistCause` field
(`BandAmbiguous` / `BandUnknown` / `IdentityUnavailable`) that names the cause directly, per band.

## Follow-up

A companion feature spec, `docs/specs/2026-08-27-instant-setlist-refresh.md`, proposes a
user-triggered instant refresh for entitled users — which would let a user in Boikot's situation fix
it themselves instead of waiting on the backoffice or the nightly job. That feature is a deliberate
amendment to this integration's existing decisions (D-65/D-67 currently rule it out); see that spec
for the tradeoffs.

**Updated 2026-08-27.** That spec was first written to cover only three of the four stuck states: a
forced re-search of an `ambiguous` band returns the same ambiguity deterministically, so the feature
would have *explained* cause 1 above — the most likely cause here — without fixing it. It has since
been amended (**D-270 – D-280**) to let the entitled user resolve the ambiguity themselves by choosing
from the candidate list the search returns. Consequences for this investigation:

- **Cause 1 (`ambiguous` / `no_presence`) now has a user-facing exit.** The user triggers a refresh,
  sees the same-named bands setlist.fm returns with their `disambiguation` text, picks the right one,
  and the shared `Band` resolves and fetches its setlists.
- **The user's pick is narrower than the operator's correction**, deliberately: only a candidate the
  server produced (never a typed MBID), only onto a band holding no MBID, and only once. An operator's
  audited `correctMbid` + `clearSetlistCache` remains the only way to change an identity that is
  already set — including one a user chose.
- **Both writes are audited and told apart by action name** — `choose_band_mbid` for a user's pick,
  `correct_band_mbid` for an operator's — so "who chose this band's identity" stays answerable.

**None of this has shipped.** The spec is still awaiting approval, and the immediate fix for Boikot is
unchanged: check `setlistfmResolutionState` in the backoffice and, if it is `ambiguous` or
`no_presence`, apply the manual MBID correction described above.
