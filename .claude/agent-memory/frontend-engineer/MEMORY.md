# Memory Index

- [Frontend stack & conventions](frontend_stack.md) — Expo/Router/TS-strict setup, theme system, generated API client; read before new screen/component work.
- [Frontend tooling gotchas](frontend_tooling_gotchas.md) — Jest/RNTL/openapi-fetch version traps hit 2026-08-21; check before re-debugging similar symptoms.
- [Auth session module](auth_session_module.md) — lib/auth/ structure, the CORS gap that had to be fixed for it to work; read before touching auth or adding a protected screen.
- [Concert tracker UI](concert_tracker_ui.md) — nav rename (/home→/concerts), the react-hooks lint pattern for syncing state from props, fetch-mock/dynamic-import test gotchas.
- [Streaming account linking](streaming_account_linking.md) — platform-fork imports must be relative (eslint), ListRow's testID-needs-onPress gotcha, dev env/migration drift when verifying a prior agent's backend.
- [Playlist fast-mode UI](playlist_fast_mode_ui.md) — PHP enum→OpenAPI string gap (now resolved), openapi-fetch 304/header handling, renderHook is async, module layout.
- [Notes and reviews UI](notes_and_reviews_ui.md) — render() must be awaited too (not just renderHook/fireEvent), array-identity-as-dep infinite loop trap, SongOutput.id backend gap, import-cycle fix.
- [Instant setlist refresh UI](instant_setlist_refresh_ui.md) — bandId report-entry backend gap, refetchInterval-closure TDZ trap, 429-is-not-an-error / 202-can-still-be-refused response shapes.
