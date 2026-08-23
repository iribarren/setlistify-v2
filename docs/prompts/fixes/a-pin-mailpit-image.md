# A — Pin the mailpit image

**Branch:** `bugfix/pin-mailpit-image` · **Priority:** High · **Independent of feature 14**

Blocks `docker compose up` on any fresh clone, so it blocks the documented first-run in `README.md`.
Five minutes of work.

```
bugfix/pin-mailpit-image

`docker compose up` is broken on a fresh clone: compose.yaml pins mailpit to
`axllent/mailpit:v1.24-alpine`, which no longer resolves upstream — `docker manifest inspect
axllent/mailpit:v1.24-alpine` fails with "manifest unknown". Compose aborts the entire `up`
before starting ANY service, so the documented first-run command in README.md does not work.

Fix it the way compose.yaml's own comment says to: that comment notes mailpit is the one image
never digest-pinned, "pin it the same way as postgres/redis above the next time this file is
touched with registry access".

1. Find a current mailpit tag that actually resolves.
2. Pin it as `tag@sha256:...`, matching the postgres/redis style in the same file.
3. Update the comment — it should no longer say the pin is outstanding.
4. Verify for real: `docker compose pull mailpit` succeeds, then `docker compose up -d` brings
   up postgres, redis, mailpit, backend and both worker replicas, and `docker compose ps` shows
   everything healthy except `worker` (which has no healthcheck by design).
5. Confirm Mailpit's UI answers on http://localhost:8025.

Do not change any other service. Commit on the bugfix branch.
```
