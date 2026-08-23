---
name: project_playlist_fast_mode_backend
description: JobStateMachine/pipeline shape, Messenger worker container gotchas (redis-messenger dep, --limit=0, shared-mount UID collisions), and static-door test technique for the playlist generation feature. Read before touching Service/Playlist/, Message/, MessageHandler/, or compose.yaml's worker service.
metadata:
  type: project
---

Implemented on `feature/playlist-fast-mode-backend`
(docs/specs/2026-08-23-playlist-fast-mode-backend.md, docs/specs/2026-08-23-spike-playlist-pipeline.md),
building on [[project_concert_domain_api]] and [[project_streaming_port_and_linking]]. Scoped to the
pipeline, Messenger and the API — the backoffice screens, the matching-quality harness, and the
spec-12/13 doc write-backs were explicitly deferred to a later pass and are NOT done.

**`symfony/redis-messenger` is a separate composer package from `symfony/messenger`.** Wiring
`async_playlist: dsn: '%env(MESSENGER_TRANSPORT_DSN)%'` (a `redis://` DSN) in
`config/packages/messenger.yaml` compiles fine and even passes `debug:container`, but the actual
worker process (`bin/console messenger:consume async_playlist`) fatals at runtime with "No transport
supports Messenger DSN ... Run composer require symfony/redis-messenger" — the container-compile
step never resolves the transport factory, only the consume command does. Always boot a real worker
(`docker compose up -d worker; docker logs <container>`) after wiring a new transport, not just
`cache:clear`/`debug:container`.

**This Symfony version's `messenger:consume --limit=0` is rejected** ("Option \"limit\" must be a
positive integer, \"0\" passed") — omit `--limit` entirely for "unlimited" rather than passing `0`.

**The backend image's `HEALTHCHECK` (curl `localhost:8080/api/health`) must be disabled
(`healthcheck: { disable: true }`) on any service reusing that image to run a non-HTTP command**
(the `worker` service runs `messenger:consume`, never listens on 8080) — otherwise the container is
permanently `unhealthy`, which can break anything using `depends_on: condition: service_healthy`
against it later.

**Running multiple replicas of a service that bind-mounts the same host directory as another running
container (`./backend:/app` shared between `backend` and `worker`) risks a UID/ownership collision if
either container's entrypoint does a startup `chown -R` of `/app`** — one worker replica's entrypoint
chowned the whole shared mount to a different UID than `backend`'s runtime user, breaking
`config/jwt/private.pem` (mode 600, now owned by the wrong uid) and `var/cache`/`var/log` writes for
every subsequent `backend` test run, with error messages (JWT key unreadable, cache/log permission
denied) that look unrelated to the actual cause. Fix: `docker compose exec -u root backend chown -R
<app-uid>:<app-gid> /app`. When adding a new multi-replica compose service that shares a bind mount
with an already-running service, verify file ownership immediately after first boot, not just that
the container itself started.

**`JobStateMachine::transition()`'s `LEGAL_EDGES` map is keyed by every one of the 11 `JobState`
enum values** (including the three terminal ones, mapped to `[]`) — PHPStan level 9 flags
`self::LEGAL_EDGES[$from->value] ?? []` as `nullCoalesce.offset` dead code once every key is present;
drop the `?? []`.

**A second top-level class defined in the same file as another class it supports (e.g. a small
pipeline-internal exception living at the bottom of a Stage's own file) autoloads correctly at
runtime only by accident — via the FIRST class's own PSR-4 file already having been `require`d for
some other reason — and PHPStan flags the second class's `catch` elsewhere as `catch.neverThrown`
even though it fires in practice.** Give every class its own file; don't rely on PHP parsing a whole
file's multiple class declarations as a substitute for autoloading correctly.

**Static "only-door" architecture tests (`JobStateMachineIsOnlyStateWriterTest` scanning for
`setStateInternal`) must exclude both the enforcing class's own file AND the entity file that
*defines* the guarded method** — a naive scan flags the entity's own method definition as a second
"caller" of itself. `SetlistGatewayIsOnlyDoorTest`'s one-directory-exclusion pattern doesn't
generalize to a single-method guard; use a list of allowed file substrings instead of one.

**`Concert::getVenue()` returns a non-nullable `Venue` value object** (default `Venue::empty()`, not
null) — don't write `$concert->getVenue()?->getCity()`; it's `$concert->getVenue()->getCity()`
(itself nullable).

**Integration tests against real DB tables that migrations seed data into (`provider_settings` —
`spotify`/`youtube` rows from D-102) must NEVER `TRUNCATE` those tables.** PHPUnit runs the whole
suite in one process against one persistent test database with no per-test transaction rollback in
this project (no dama/doctrine-test-bundle) — a `TRUNCATE ... provider_settings ... CASCADE` in one
test class's `setUp()` permanently deletes the migration-seeded `spotify` row for every test that
runs afterward in the same process, producing failures in unrelated test classes with no obvious
connection to the actual cause. Only truncate tables the feature itself owns exclusively
(`playlist_generation_jobs`/`playlists`/`playlist_tracks`/`track_resolutions` here); for shared
config rows, find-or-create idempotently and restore any mutated flag (e.g. `enabled`) in
`tearDown()`. Also clear the specific Redis cache key (`ProviderRegistry::CACHE_KEY`) after such a
write rather than guessing a TTL will save you — `ProviderRegistry`'s 300s cache means a stale read
can otherwise poison every test for up to 5 minutes.

See [[project_streaming_port_and_linking]] for the port/adapter conventions this pipeline consumes
through `StreamingProviderInterface` only, and [[project_concert_domain_api]] for the
owner-extension/locator/voter shape `PlaylistOwnerExtension`/`PlaylistGenerationJobOwnerExtension`
copy exactly.
