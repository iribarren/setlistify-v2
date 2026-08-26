---
name: project_notes_and_reviews
description: API Platform 4's real write pipeline (no per-listener operation re-resolution), Doctrine EntityManager-closes-on-flush-failure, and KernelBrowser-reboot-safe test-double patterns learned implementing notes-and-reviews. Read before adding another singleton create-or-update (PUT-with-allowCreate) resource, another race-safe write, or another cross-request test spy.
metadata:
  type: project
---

Implemented on `feature/notes-and-reviews` (docs/specs/2026-08-26-notes-and-reviews.md, D-227–D-247),
building on [[project_concert_domain_api]] (`ConcertOwnerExtension`/`ConcertLocator` pattern, copied
verbatim for `ConcertReview`) and [[project_backoffice_foundation]] (read-only CRUD controller shape).

**API Platform 4's actual write pipeline is ONE synchronous call chain
(`ApiPlatform\Symfony\Controller\MainController` → a decorated `ProcessorInterface` chain:
`SerializeProcessor` → `WriteProcessor` (calls your custom `processor:`) → `RespondProcessor`), NOT
the older per-listener `kernel.view` event pipeline** — `debug:event-dispatcher kernel.view` shows
nothing API-Platform-specific registered at all in this app. Consequence: **a custom processor
CANNOT change the response status by mutating `$request->attributes->set('_api_operation', ...)`** —
downstream processors receive the SAME `$operation` object MainController built once, passed as a
plain function argument, never re-read from the request. `ApiPlatform\State\Util\
HttpResponseStatusTrait`'s built-in "PUT+allowCreate+no previous_data → 201" auto-detection also
never fires for a DTO-output custom resource, because it requires
`resourceClassResolver->isResourceClass(get_class($returnedDto))` to be true, and an output DTO
(`ConcertReviewOutput`) is never itself `#[ApiResource]`. **The actual fix**: on a genuine create,
build and return a real `Symfony\Component\HttpFoundation\Response` yourself (serialize via the
`api_platform.serializer` service + `SerializerContextBuilderInterface`, matching what
`SerializeProcessor` does internally) with the status you want — every stock API Platform processor
in the chain special-cases `$data instanceof Response` as an immediate pass-through, so this is a
supported shortcut, not a hack. See `App\State\Processor\ConcertReviewPutProcessor::respondCreated()`.
Inject the serializer via `#[Autowire(service: 'api_platform.serializer')]` — a plain
`SerializerInterface` autowire resolves to Symfony's default serializer, not API Platform's
JSON-LD-aware one.

**A failed `EntityManager::flush()` (e.g. a caught `UniqueConstraintViolationException` from a
race-safe insert) closes the WHOLE EntityManager in this ORM version, not just the SQL transaction**
— `$connection->rollBack()` alone is not enough; any further `persist()`/`flush()` on the same
`$this->entityManager` throws "The EntityManager is closed." `App\Service\Concert\BandResolver`'s
precedent (a documented earlier race-safe insert) never hit this because it never writes again after
catching — it just re-reads and returns. A retry that then needs to WRITE (our create-or-update PUT)
must fetch a truly fresh manager via `Doctrine\Persistence\ManagerRegistry::resetManager()` +
`getManager()` (guarded by `!$entityManager->isOpen()`), and re-fetch repositories from THAT manager
— the original processor's injected repository still wraps the closed one.

**`Symfony\Bundle\FrameworkBundle\Test\KernelBrowser` rebuilds the WHOLE container — a fresh instance
of every service, test doubles included — on every single `$client->request()` call.** Any test
double that needs to inject behavior/state that must survive from "before the request" to "during
the request" (arming a race-condition hook, a request counter meant to span multiple calls) MUST use
`private static` properties, not instance properties — an instance armed before the request is a
different object than the one the request's own container hands to Doctrine/whatever hooks into it.
Two such doubles now exist: `App\Tests\Support\ConcertReview\ConcertReviewRaceInjector` (a
`doctrine.event_listener` on `prePersist`, registered `when@test` in `config/services.yaml`, armed
via a public `arm(ownerId, concertId)`) and `App\Tests\Support\Setlist\CountingSetlistFmHttpClient`
(decorates the `setlistfm.client` scoped `HttpClientInterface`, same `when@test` pattern). Both are
the reusable shape for "I need to intercept/observe something mid-request in a KernelBrowser test."

**Simulating a genuine insert race in single-threaded PHPUnit needs a SECOND, truly independent DBAL
connection for the "concurrent" side, not the same connection inside the processor's own
transaction/savepoint** — inserting the "other request's" row on the SAME connection as the
processor's own `beginTransaction()` means the processor's later `rollBack()` (on catching its own
unique-violation) undoes the injected row too, since it's in the same savepoint scope. Open a second
connection via `Doctrine\DBAL\DriverManager::getConnection($existingConnection->getParams())`, insert
+ let it autocommit (no explicit transaction), then `close()` it — it survives the first connection's
rollback because it was never part of that transaction.

**Doctrine Migrations classes are deliberately NOT PSR-4 autoloaded** (composer.json's own comment on
`migrations_paths`) — a test that wants to execute one directly (this feature's first-ever migration
test in this repo) must `require_once` the file by path, and PHPStan needs `scanDirectories: [migrations]`
added to `phpstan.neon.dist` (NOT `paths`, which would lint them) purely so the class is resolvable
for reflection. `Doctrine\Migrations\AbstractMigration::addSql()` accumulates into ONE internal queue
for the object's whole lifetime — reusing the same instance for both `up()` and `down()` replays
`up()`'s already-applied SQL alongside `down()`'s; always construct a fresh instance per direction.
Given this repo's tests share one persistent Postgres database with no per-test rollback (see
[[project_playlist_fast_mode_backend]]), a migration test should run against a THROWAWAY schema
(`CREATE SCHEMA x; SET search_path TO x`) seeded with only the minimal tables the migration's own SQL
touches — never replay the whole migration chain's down()/up() against the shared `public` schema,
which risks clobbering migration-seeded rows other test classes depend on.

**This app's JSON-LD serialization OMITS a property entirely when its value is `null`, on every
operation (GET item, GET collection) — despite `ConcertOutput`'s own docblock claiming "omitted
optional fields are null, never absent" (D-29/AC-2.7).** This is pre-existing, silent behavior, not
something this feature introduced — `ticketPrice`/`doorsTime`/`startTime` are equally omitted-not-null
on every existing GET response; the ONLY reason `ConcertCreateTest`'s prior `assertNull($data['ticketPrice'])`
assertions ever passed is that PHP's undefined-array-key access yields `null` anyway (with a warning),
so the assertion is true whether the key is absent or actually `null`. A test that wants to prove "key
present, value null" (vs. "key merely absent") needs `assertArrayHasKey` explicitly — this codebase's
existing suite never actually verified that distinction, and I chose not to change the framework's
serialization behavior to fix it (out of scope, likely to have wide blast radius) — flagged in this
memory instead. `App\Tests\Functional\ConcertReview\ConcertReviewListIndicatorTest` documents this
and asserts the ACTUAL behavior (`$data['reviewSummary'] ?? null`), not the docblock's claim.

**`ApiPlatform\Doctrine\Orm\Paginator::getIterator()` is NOT idempotent** — with
`fetchJoinCollection: true` it re-issues an id-subquery AND a WHERE-IN query on EVERY call, so
iterating it twice (once to inspect the page, once via the normal serialization pass) silently
doubles the query count. `App\State\Pagination\MaterializedPaginator` solves this: read pagination
metadata (`getCurrentPage()` etc. — none of them touch `getIterator()`) first, THEN materialize the
page via `iterator_to_array()` exactly once, and hand a wrapper that just replays that array to
whatever consumes it next (`App\State\Pagination\MappingPaginator`). Needed here because
`App\State\Provider\ConcertCollectionProvider` batch-fetches `ConcertReview` summaries for the
page's own concert ids in a SECOND query, which requires materializing the page's concerts first.

**Doctrine ORM's `Doctrine\ORM\Tools\Pagination\Paginator` supports mixed hydration (entity + extra
scalar `addSelect()` aliases) fine at the SQL level, but wiring it through this app's own
`MappingPaginator<TIn of object, TOut>` generic (`TIn` constrained `of object`) doesn't accept the
resulting array rows** — rather than fight that, `ConcertCollectionProvider` joins `ConcertReview`
into the main query ONLY for filtering (`?reviewed=`), selecting none of its columns (so hydration
stays plain `Concert` entities), and fetches review summaries via a wholly separate single batch
query afterward. Simpler and safer than mixed hydration; costs one extra query total, not one per row.

See [[project_concert_domain_api]] for the `ConcertOwnerExtension`/`ConcertLocator` shape this
feature's `ConcertReviewOwnerExtension` copies verbatim (never a shared base class — D-229's own
rationale), and [[project_backoffice_foundation]] for the read-only-CRUD-by-default convention
`ConcertReviewCrudController` follows.
