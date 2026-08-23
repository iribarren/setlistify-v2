# `MessageHandler/`

> `BuildPlaylistHandler`.

Playlist generation runs asynchronously via Messenger + Redis (`docs/architecture.md` §8,
`docs/specs/2026-08-23-playlist-fast-mode-backend.md` §9). `BuildPlaylistHandler` is five steps, in
order: acquire the `playlist-job-<id>` lock (non-blocking — a redelivery on an in-flight job is a
no-op, T-20), re-read the job row inside the lock, delegate to
`App\Service\Playlist\PlaylistPipeline::run()`, catch `GenerationBlockedException` (acknowledged,
never retried — a blocked job is resumed by `app:playlist:resume-blocked` or a user action), and let
any other `\Throwable` propagate so Messenger's own retry policy applies (F-12). It contains no
business logic of its own.
