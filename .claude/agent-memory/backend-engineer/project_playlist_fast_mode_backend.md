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

## Test-scope backfill on `bugfix/playlist-fast-mode-failure-tests` (2026-08-23)

Filled the test scope from ~3 tests to ~36 (integration + functional), and found/fixed two real
production bugs the missing tests had been hiding. Both are described in full below because they're
the kind of thing that silently regresses if a future refactor "simplifies" either spot back.

**Bug 1 — `SetlistSelectionStage::run()` unconditionally created a NEW `Playlist` (with a fresh
`PlaylistTrack` skeleton) every time the pipeline re-entered `resolving_setlist`, which happens on
EVERY resume (T-13 `blocked -> queued`) or retry (T-16 `failed -> queued`) — not just the first
attempt.** This silently orphaned the original `Playlist` (which may already carry a confirmed
`providerPlaylistId`, D-136, or a partially-advanced insertion watermark, D-137) and made a resumed
run call `createPlaylist()` and `addTracks()` a second time for a job that had already progressed
past selection — the exact duplication AC-6/D-136/D-137 exist to prevent, and spec 13 §5's claim
"there is no stage from which a retry is unsafe" was false until this fix. Fix: `run()` now checks
`playlistRepository->findOneBy(['job' => $job])` first and returns the existing row untouched if one
exists — a no-op precisely when a prior attempt already got past selection, and a normal fresh build
when it didn't (e.g. a job blocked earlier, at F-01, never even reached that point). **Any stage that
appears "purely local, so retry-safe" needs to actually check for its own already-produced artifact
before recreating it — the claim isn't free, `SetlistSelectionStage` just hadn't been exercised by a
resume/retry test before.**

**Bug 2 — `PlaylistResponseHeadersSubscriber` never actually implemented
`Symfony\Component\EventDispatcher\EventSubscriberInterface`, only its static `getSubscribedEvents()`
method by duck typing.** Symfony's `services.yaml` autoconfiguration only auto-tags classes that
literally implement the interface as `kernel.event_subscriber` — this class compiled fine, satisfied
the shape, but was never wired to ANY event (confirmed via `bin/console debug:event-dispatcher
kernel.response`, absent from the listener list entirely). Consequence: the ENTIRE polling contract —
`ETag`/304, per-state `Retry-After`, and the "second POST for a live job returns 200 not 201"
override — was dead code from day one, and every read still succeeded (200 with a body), so nothing
about the happy path looked broken; only a test asserting the actual header/status contract catches
this. Fix: add `implements EventSubscriberInterface`. **When adding a new `kernel.*` event listener as
a plain class with `getSubscribedEvents()`, always verify registration with `bin/console
debug:event-dispatcher <event>` — don't trust that "it compiles" means "it's wired."**

**`WebTestCase`'s `KernelBrowser` reboots the kernel — and rebuilds its container, hence a fresh
`EntityManager` — on every `$client->request()` call.** Any entity object fetched from
`static::getContainer()` BEFORE a request and then reused in a `persist()` call AFTER one is a
detached instance from a since-discarded `EntityManager`, and Doctrine throws
`ORMInvalidArgumentException: A new entity was found through the relationship...` the moment it's
referenced by something newly persisted. Reading a plain scalar getter (`->getId()`) on the stale
object is fine (no EM involved); persisting something that references it is not. Fix used here:
helper methods that create fixtures between HTTP calls take a plain `string $email`/`int $id` and
refetch the entity fresh from the (current) container every time, never an entity parameter.

**The anti-starvation partial unique index `uniq_live_generation_per_user`(D-144) — one live job
(`queued`/`resolving_setlist`/`matching`/`building`) per OWNER across ALL concerts — bites in tests
that create multiple jobs for the SAME user to exercise different states, even across different
concerts.** A test creating a `queued` job and then trying to create a second job (to move to
`matching`, etc.) for the same user before moving the first one out of a covered state throws a
`UniqueConstraintViolationException` on that index. Use a separate registered user per state under
test, not a shared one, when a test needs several jobs in different LIVE states simultaneously.

**`TestDoubleStreamingProvider` (`backend/tests/Support/Streaming/`) gained failure-injection
scripting** (`scriptQuotaExhaustedAtSearchCall()`/`AtAddTracksCall()` — exact 1-based call number,
one-shot via `===` not `>=`, so a later retry's calls succeed normally; `scriptRateLimitedAtSearchCall()`;
`scriptRefreshTokenExpires()`; `scriptTrackId()` (per-song-title id override); `scriptNoCandidates()`;
`scriptVanishedTrack()`/`scriptRegionRestrictedTrack()` (by provider track id); call counters
`getSearchTrackCallCount()`/`getCreatePlaylistCallCount()`/`getAddTracksCallCount()`/
`getAddTracksCallLog()`; `reset()`) and is now `public: true` in `config/services.yaml`'s `when@test`
block so a test can fetch the exact tagged instance and script it. **`addTracks()` marks the WHOLE
batch with one outcome on a batch-level exception (`RegionRestrictedException`/`NotFoundException`)
— the frozen 9-method port (D-71) gives no way to know which id in a multi-track call was the actual
cause.** A test wanting to prove a truly PER-TRACK outcome (not "per-batch") needs more songs than
`GENERATION_INSERT_BATCH_SIZE` (50) so the track under test lands alone in its own batch.

**`SetlistFmBudget`'s Redis keys (`setlistfm:budget:<date>`, `setlistfm:breaker:failures`,
`setlistfm:breaker:open_until`) are shared, real, and read in THIS priority order: breaker, then
daily budget, then per-second rate token** — a test that scripts budget exhaustion without also
snapshotting/clearing the breaker keys first is flaky under full-suite runs if an unrelated earlier
test left the breaker open (a real, if low-probability, source of cross-suite pollution on a shared
Redis key). Snapshot-and-restore both breaker keys the same way as the budget key.
