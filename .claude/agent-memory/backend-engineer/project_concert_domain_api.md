---
name: project_concert_domain_api
description: API Platform / Doctrine gotchas hit implementing the concert domain (custom DTO-bound resources, merge-patch, generics, container caching). Read before adding another user-scoped DTO-bound resource (playlists, notes, prompts 14-20).
metadata:
  type: project
---

Implemented on `feature/concert-domain-api` (docs/specs/2026-08-21-concert-domain-api.md), building
on [[project_symfony_skeleton]] and [[project_auth_and_accounts]]. This is the first resource in the
app with item-level `PATCH`/`DELETE` and a fully custom (non-entity-bound) provider/processor pair —
several things here don't show up until you build exactly that shape.

**A `Patch`/`Delete` operation with a custom `processor:` still runs API Platform's built-in Read
stage first, unless you set `read: false`.** Without it, `ReadProvider` tries to load the resource
class (`ConcertResource`, not an entity) via the default pipeline, finds nothing, and 404s *before*
your processor's own ownership-aware lookup (`App\State\ConcertLocator`) ever runs. `Get`/`Post`
don't need this because their providers/processors ARE the read/write step.

**API Platform's generic `ItemNormalizer::denormalize()` hardcodes special handling for a top-level
`id` key in ANY request body**, regardless of whether the target DTO declares an `id` property. It
tries `IriConverter::getResourceFromIri((string) $data['id'], ...)`; for a bare non-IRI value that
throws, and it falls back to constructing *the current route's own* IRI and re-resolving that — which
fails with a 400 ("does not reference the correct resource") if the DTO class isn't itself a
resource (ours isn't — `ConcertPatchInput` is an `input:`, not the resource class). Net effect: a
client sending `"id": <anything>` in a PATCH/POST body to a DTO-bound resource gets a 400, not a
silent ignore. This is actually fine for AC-5.5-style "id must not be settable" guarantees (it can't
succeed either way) but don't write a test expecting 200-with-ignored-id — write a name that doesn't
include a top-level `id` key, and instead assert 400 if you want to cover the "id in body" case.

**`Symfony\Component\Serializer\Attribute` array-of-DTO properties (`@var list<LineupEntryInput>`)
denormalize as raw associative arrays, not objects, unless `phpdocumentor/reflection-docblock` (+
`phpdocumentor/type-resolver`) is installed.** `PropertyInfo`'s `PhpDocExtractor` is what reads the
docblock to learn the collection's value type; without it, `ObjectNormalizer` has no way to know
`$lineup[0]` should become a `LineupEntryInput` instance, and code that does `$entry->name` on it
fails with "Attempt to read property on array". Added as a real composer dependency (not just
transitively present) — any future DTO with an array-of-DTO property depends on this.

**A `debug:false` `KernelTestCase::createClient(['debug' => false])` boots a *different, separately
cached* container** (`var/cache/test/...DebugContainer` vs the non-debug one), and — unlike the
debug container — it does **not** auto-invalidate when source files change. Editing `src/` and then
running only debug:false tests can fail with a generic 500 ("Processor ... not found on operation")
that has nothing to do with your change; the fix is `rm -rf var/cache/test` (or `var/cache/dev` too,
if `bin/console --env=dev` was also used) before trusting a debug:false failure. `ProblemDetailsTest`
already establishes debug:false as the pattern for asserting byte-identical error bodies (stack
traces differ by call site under debug:true) — `ConcertOwnershipTest`'s 404-body-equality tests reuse
it for exactly the same reason.

**`Doctrine\DBAL\Connection::setNestTransactionsWithSavepoints(true)` is deprecated with "no
replacement planned" in this DBAL version — because `getNestTransactionsWithSavepoints()` now always
returns `true` unconditionally.** A nested `beginTransaction()` already uses a savepoint by default;
just delete the call. (`App\Service\Concert\BandResolver`'s race-safe insert relies on this.)

**A `QueryParameter`'s declared `schema` (min/max/enum) is enforced by API Platform itself before
your custom provider runs, unless `queryParameterValidationEnabled: false` is set on the operation.**
If the intent is "silently clamp an out-of-range value" (AC-3.5's `itemsPerPage` cap) rather than
"422 on out-of-range", the schema must be documentation-only — set that flag, and do the clamping in
the provider. Left enabled, a client sending `itemsPerPage=1000` gets a 422 instead of a clamped 100.

**PHPStan level 9 generics for a custom `PaginatorInterface` wrapper**: `ApiPlatform\State\Pagination\
PaginatorInterface<T>` requires `T of object`, so a wrapper's own `@template TOut` needs `of object`
too, or PHPStan rejects the `@implements`. Also, `ApiPlatform\Doctrine\Orm\Paginator` (the built-in
Doctrine ORM paginator) is **not itself generic** — wrapping it means PHPStan only knows it yields
`object`, not the concrete entity; narrow with `\assert($item instanceof Concert)` inside the mapping
closure rather than fighting the type.

**Custom DTO-bound resources bypass API Platform's automatic Doctrine filter/extension pipeline
entirely** (no `QueryCollectionExtensionInterface` auto-run, no `FilterInterface` auto-apply) — this
only fires for the built-in entity-bound `CollectionProvider`/`ItemProvider`. Still implement
`ConcertOwnerExtension` against the real AP interfaces (`QueryCollectionExtensionInterface`/
`QueryItemExtensionInterface`) for future reuse by an entity-bound resource, but call
`applyToCollection`/`applyToItem` manually from the custom provider — same for any per-property
filter logic (`ConcertStatusFilter`, `ConcertBandNameFilter` here are plain service classes, not
`ApiPlatform\Doctrine\Orm\Filter\FilterInterface` implementations, applied by hand inside the
provider). Document query parameters for OpenAPI via `#[QueryParameter]` on the operation instead of
`#[ApiFilter]` — it's the AP4-native way to describe a param whose actual application is manual.

See [[project_symfony_skeleton]] for the base app conventions this feature builds on, and
[[project_auth_and_accounts]] for the earlier DTO/processor pattern (`RegisterUserProcessor`,
`MeStateProvider`) this one continues.
