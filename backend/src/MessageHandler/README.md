# `MessageHandler/`

> `BuildPlaylistHandler`, `RefreshTokenHandler`.

Out of scope for this feature — Messenger transport configuration and async jobs are not
configured here (`MESSENGER_TRANSPORT_DSN` exists as a variable, but no transport, message or
handler is wired up). See the "Out of Scope" section of
`docs/specs/2026-08-21-backend-skeleton.md`.

Playlist generation runs asynchronously via Messenger + Redis (`docs/architecture.md` §8) — this is
where the handlers for that live once it is built.
