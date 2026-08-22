---
name: project_backoffice_provider_configuration
description: EasyAdmin Actions API, php-cs-fixer docblock corruption, JSON-LD collection envelope shape, and Doctrine identity-map test gotchas hit implementing the provider kill-switch/playbackMode backoffice feature. Read before touching Service/Provider/, Controller/Admin/*CrudController.php (any EDIT-enabled one), or writing a strict-allowlist test against a JSON-LD collection response.
metadata:
  type: project
---

Implemented on `feature/backoffice-provider-configuration`
(docs/specs/2026-08-22-backoffice-provider-configuration.md, D-89..D-105), building on
[[project_streaming_port_and_linking]] and [[project_backoffice_foundation]].

**`EasyCorp\Bundle\EasyAdminBundle\Config\Actions::disable()` is append-only — there is no
`enable()` counterpart in this installed version.** `AbstractAdminCrudController::configureActions()`
calls `->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)`; a subclass that
wants EDIT back (this feature's first EDIT-enabled backoffice screen) must NOT call
`parent::configureActions()` at all — there is no way to walk a single disabled action back via the
public API (`ActionConfigDto::$disabledActions` only grows). The fix is to replicate the parent's
disable list minus the one action you want, e.g. `return $actions->disable(Action::NEW,
Action::DELETE, Action::BATCH_DELETE);`. Also: `Actions::add(Crud::PAGE_INDEX, Action::EDIT)` throws
("already exists in the index page") if EDIT was never removed — `add()` is for genuinely new custom
actions, not for un-disabling a built-in one.

**php-cs-fixer's `phpdoc_no_alias_tag` rewrites the literal text `@type` to `@var` ANYWHERE inside a
`/** */` docblock, including in the middle of a prose sentence, not just at tag position** — it hit
a docblock reading "exactly `@id`, `@type`, `key`, ..." and silently corrupted it to "`@id`, `@var`,
...". This is a new instance of the same class of bug documented in
[[project_streaming_port_and_linking]] (the `phpdoc_no_useless_inline_doc_block` collateral damage)
— always re-read a docblock after running `php-cs-fixer fix` if it contains the literal string
`@type` (or likely other tag-alias names: `@link`→`@see`, `@property-read`, etc.), and diff before
trusting the auto-fix. Workaround: don't spell `@type` literally in prose — describe it ("the JSON-LD
type envelope key") instead.

**This codebase's API responses are `application/ld+json` only (no plain `json` format configured in
`config/packages/api_platform.yaml`)** — a `GetCollection` response is `{"member": [...], "@context":
..., "totalItems": ...}`, not a bare JSON array, and each item in `member` carries `@type` and `@id`
(a `/api/.well-known/genid/<hash>` blank-node IRI when the DTO has no identifier property) *in
addition to* the declared output properties. A strict-allowlist test against such a response (the
AC-6.4-shaped "exactly these fields, forever" test `StreamingAccountApiTest` already established)
must include `@type`/`@id` in its expected key list, not just the DTO's own constructor properties —
learned by getting `testEveryItemHasExactlyTheDeclaredFieldsAndNoCredentialShapedKey` wrong first.
Also: a credential-shape regex ending in `key$` will false-positive on a legitimate field literally
named `key` (e.g. a provider's key) — use `.+key$` (require a prefix) or special-case the exact field
name, not a bare `key$` anchor.

**A raw SQL write via `$connection->executeStatement(...)` (bypassing the ORM) leaves Doctrine's
identity map stale for any entity of that row already loaded in the same `EntityManager`/process** —
a subsequent `$repository->find()`/`findBy()` call returns the *already-loaded, now-stale* PHP object
rather than re-querying, even though the DB row genuinely changed. This bit a cache-invalidation test
that warmed a registry read (loading the ORM entity), then wrote around the ORM, then expected a
"fresh" read to see the new value — it silently didn't, because "fresh" only meant a new PHP object of
the *service*, not a new persistence context. Fix: call `$entityManager->clear()` after any raw-SQL
test write that a later ORM read in the same test needs to see. This is the same staleness family
documented in [[project_backoffice_foundation]] for `KernelBrowser` reboots, just triggered by SQL
instead of a request boundary.

**A partial unique index (`CREATE UNIQUE INDEX ... WHERE is_default`) enforced via two sequential
`UPDATE` statements in a test fixture reset can transiently violate itself** — `UPDATE ... SET
is_default = true WHERE provider = 'a'` executed while `provider = 'b'` still has `is_default = true`
from a previous test's leftover state throws a real Postgres `UniqueConstraintViolationException`,
even though the *final* state (after a second statement) would be valid. Any test helper that resets
more than one row's `is_default`-shaped flag must first clear ALL rows' flag to false, then set the
one intended row true — never assume "set row A true, set row B false" is safe just because that's
the intended end state; the two statements aren't atomic with each other.

**Assertion-heavy audit-log tests must scope their query to entries created *during* the test, not
just `findBy(['subjectType' => X, 'subjectId' => Y])` after the fact** — `AuditLogEntry` rows for a
migration-seeded singleton (here, the `spotify`/`youtube` `ProviderSetting` rows) accumulate across
every test method in the class *and every other test class in the same suite run* (real shared
Postgres, no per-test rollback in this codebase's functional tests). A "no entry for this unchanged
field" assertion will intermittently see an entry from a *different* test's write to the same
subject. Fix: snapshot entry ids before the action under test, then diff against ids after — see
`entriesCreatedDuring()` in `ProviderSettingWriterTest`.

**`ApiPlatform\Metadata\Exception\ProblemExceptionInterface` (not Symfony's `HttpExceptionInterface`)
is the clean way to give a domain exception its own RFC 7807 `type`/`status` without a controller-side
catch-and-rethrow** — implement it directly (`getType()`, `getTitle()`, `getStatus()`, `getDetail()`,
`getInstance()`), matching `ApiPlatform\State\Exception\ParameterNotSupportedException`'s shape; API
Platform's `ErrorListener` reads `getStatus()` off it directly (verified by reading
`vendor/api-platform/symfony/EventListener/ErrorListener.php`). Letting it propagate unhandled from a
processor/provider is correct — a redundant catch-and-rethrow is dead code.

**Doctrine ORM's native `enumType:` option on `#[ORM\Column]` is enough for a backed-enum column**
(`#[ORM\Column(type: 'string', length: 20, enumType: PlaybackMode::class)]`) — no custom Doctrine
`Type` class needed, unlike [[project_streaming_port_and_linking]]'s `EncryptedStringType` (which
needed one because encryption isn't a Doctrine built-in, not because enums do).

See [[project_streaming_port_and_linking]] for the `ProviderAvailability` seam this feature filled in
(D-86→D-89), and [[project_backoffice_foundation]] for the CRUD-controller/audit-logger conventions
this feature's `ProviderSettingCrudController`/`ProviderSettingWriter` continue.
