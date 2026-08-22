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
