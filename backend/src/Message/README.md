# `Message/`

> The message DTOs themselves.

`BuildPlaylistMessage` — `{ jobId, attempt }` and nothing else (D-125). Routed to the
`async_playlist` transport (`config/packages/messenger.yaml`); every other input the pipeline
needs is a column on `PlaylistGenerationJob`, which is what keeps a redelivered message idempotent.
