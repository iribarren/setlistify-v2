# TODO

Deferred items and things only the project owner can provide. Not a backlog (that's
`docs/prompts/`) — this is for loose ends that fell out of implemented features.

## API keys / credentials needed

- **`SETLISTFM_API_KEY`** — no live key was available while implementing prompt 09
  (setlist.fm integration). Needed for:
  - Capturing real fixtures to replace the hand-constructed ones (see below).
  - Running the `@group live` smoke test (`docs/specs/2026-08-22-setlistfm-integration.md`
    AC-13.3) before any release that touches this code.
  - Actually running the app against real data at all — right now it's untested against the
    live API end-to-end.
- **`SPOTIFY_CLIENT_ID` / `SPOTIFY_CLIENT_SECRET`** — only placeholder values exist in this
  environment while implementing prompt 10 (streaming port and account linking). Needed for:
  - Allowlisting real test accounts in the Spotify dashboard (Development Mode caps the app at
    5 users — see `docs/specs/2026-08-22-streaming-port-and-account-linking.md` R-2).
  - Registering two separate app registrations (dev and prod) with different redirect URIs, per
    `docs/env-vars.md`.
  - Actually clicking through the OAuth consent screen to verify AC-1.6 (link flow completes on
    web, iOS and Android) — this was not verifiable end-to-end without real credentials.
  - Capturing real fixtures and running the `@group live` smoke test (D-85) before any release
    that touches this code.
  - Verifying the native (iOS/Android) OAuth round trip on a real device or simulator, which
    also wasn't available in this environment.
  - **Capturing the 8 real Spotify search-response fixtures** the matching-quality gate needs to
    arm (see below) — a Client Credentials token is enough, no user scope required.

## Deferred / follow-up work

- **Replace hand-constructed setlist.fm fixtures with real captures.** The default test suite's
  fixtures (multi-candidate search, empty search, large index, covers/tape/encores, empty
  setlist, 429, 500 — `docs/specs/2026-08-22-setlistfm-integration.md` AC-13.4) were written by
  hand from the documented API shape, not captured live, because no API key was available. They
  should be re-captured once a key exists, since a subtle shape mismatch would go undetected
  until the live smoke test or production.
- **Apply for setlist.fm's higher rate tier** (16 req/s, 50,000/day vs the standard 2/s,
  1,440/day) — costs nothing to ask, raises the ceiling 35×. Purely operational, doesn't block
  any code (D-69 in `docs/architecture.md`).
- **Wire up the nightly `app:setlist:refresh` job as an actual cron entry** in the deployment
  target. It's implemented and documented in the README's Operations section but nothing
  schedules it yet outside of running it manually.
- **Watch `docs/specs/2026-08-22-setlistfm-integration.md`'s two tuned defaults** once there's
  real usage: the 7-day post-concert refresh window and the 25% nightly-job budget share
  (AC-10.1, AC-10.3). Both are guesses made without data, both are env-configurable, and the
  backoffice dashboard (US-11) exists partly to tell you if they need adjusting.
- **Manually click through `/admin`'s new Providers screen** before merging prompt 11
  (`docs/specs/2026-08-22-backoffice-provider-configuration.md`). Implementation was verified by
  automated tests only (functional tests + a rendered-HTML crawl for the SDA help text, AC-3.5),
  not by a human looking at the real form — worth doing given the screen's whole purpose is that
  an operator understands the legal consequence of `playbackMode` at the moment of the click
  (R-4). The stack (`docker compose`) is already up and ready for this.
- **Arm the matching-quality gate** (`docs/specs/2026-08-22-spike-song-matching.md` §9, D-122/
  D-123). `backend/tests/Matching/MatchingQualityHarnessTest.php` and
  `MatchingFixtureFreezeTest.php` exist and run in the default suite, but the catalog-dependent
  auto-accept-precision check `markTestSkipped`s — there are no captured Spotify search
  responses and no human-labelled ground truth yet. Needs: a Spotify Client Credentials token,
  the 8 real setlists named in spec §9's fixture table (Radiohead MSG 2018, Springsteen
  Barcelona 2023, Pearl Jam 2022, Metallica Amsterdam 2023, Sigur Rós 2022, Vetusta Morla Madrid
  2023, Phish 2023, Alcalá Norte's Vetusta Morla support slot 2023), one captured search response
  per song under `backend/tests/Fixtures/matching/catalog/spotify/`, and one human labelling pass
  (~180–220 entries) added to `backend/tests/Fixtures/matching/manifest.yaml`'s `catalog:`
  section per the template documented there. Regenerate `manifest.sha256` and commit fixtures +
  checksum together, with no algorithm change in the same PR (D-122's freeze rule).
- **Run the Layer-3 device matrix for `feature/concert-page-player-embed`**
  (`docs/specs/2026-08-26-concert-page-player-embed.md`, D-225): 3 `playbackMode` values ×
  Spotify × {web, iOS, Android} = nine cells, plus the "flip `playbackMode` in `/admin`, watch an
  already-open client change with no rebuild" check (AC-4.1) on each platform. Not run in this
  environment — no iOS/Android device or simulator was available, and no real Spotify embed was
  loaded in a browser against a live `docker compose` stack. Everything Jest can prove (Layers 1
  and 2, including forcing each platform's `PlaybackEmbed` resolution via `jest.mock`) is green;
  this is the checklist item the PR description should carry and a reviewer should check off
  before merging, per the spec's own Layer 3 section.
- **Ask Spotify in writing whether their iframe embed classifies an app as a Streaming SDA**
  (`docs/external-apis.md` §Spotify's open question, restated by
  `docs/specs/2026-08-26-concert-page-player-embed.md` Risk 1) — now more pressing since the
  embed is live on web behind `playbackMode`. Does not block anything; the answer changes a flag
  value, not code.
- **Visually verify `feature/notes-and-reviews`'s phone-vs-desktop review editor and the
  setlist-backed highlight picker** (`docs/specs/2026-08-26-notes-and-reviews.md`, D-245, US-5).
  Only `tsc`/eslint/jest-expo covered these in this environment — no real browser or iOS/Android
  simulator was used to confirm the sheet-vs-inline breakpoint switch or the picker's live
  band/setlist grouping render correctly.
- **Decide a backup/point-in-time-recovery policy for the Postgres instance** before public launch
  (`docs/specs/2026-08-26-notes-and-reviews.md`, open question 1). `ConcertReview` is the first
  feature whose data (personal writing) can't be reconstructed if lost — a concert row can be
  re-entered from a ticket stub, a review can't. Not resolved by this feature; needs its own
  infrastructure prompt.
- **Run a genuine multi-process concurrency test for AC-5.6/AC-6.14**
  (`docs/specs/2026-08-27-instant-setlist-refresh.md`, instant setlist refresh). The delivered
  tests (`SetlistRefreshCoordinatorTest`, the pick's functional coverage) exercise the throttle
  and precondition logic with sequential calls only — the `symfony/lock` per-band serialization is
  implemented and its state-check-then-write ordering is correct by inspection, but no test in
  this branch actually races two real concurrent processes/requests against the same band the way
  `ConcertReviewRaceInjector`'s second-DBAL-connection technique
  (`.claude/agent-memory/backend-engineer/project_notes_and_reviews.md`) does for a genuine insert
  race. Worth adding before this feature sees real concurrent traffic.
- **Manually click through `/admin`'s new "Grant / revoke instant refresh" action and the
  dashboard's new setlist.fm figures** before merging
  (`docs/specs/2026-08-27-instant-setlist-refresh.md`, US-7, US-9). Verified by functional tests
  and a full local `phpunit` run only — no human looked at the rendered confirmation template or
  the dashboard panel's layout.
- **Judgment call to revisit**: when the ambiguity pick's identity write succeeds but its
  completion throttle (daily cap / budget reserve) then refuses, `ResolveBandIdentityProcessor`
  still returns `202` with `refusedReason` set rather than a `429` — the band's identity write is
  real and audited either way, only the immediate setlist fetch didn't happen. The spec's D-275/
  AC-6.12 text is not fully explicit about this edge case (a user who happens to be exactly at
  their daily cap at the moment they pick); flagged here rather than assumed silently correct.
- **Apply for setlist.fm's higher rate tier is unrelated to this feature's own four new env vars**
  (`SETLISTFM_REFRESH_NOW_COOLDOWN`/`_DAILY_PER_USER`/`_BUDGET_RESERVE`/`_TOKEN_WAIT`) — all four
  are unmeasured starting points (spec's own Assumptions list) and, like the nightly job's tuned
  defaults above, should be revisited once US-9's dashboard shows real usage.
