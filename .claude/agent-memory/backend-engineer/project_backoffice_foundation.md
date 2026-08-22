---
name: project_backoffice_foundation
description: EasyAdmin/scheb-2fa/Symfony gotchas hit implementing the admin backoffice (second firewall, forced 2FA enrollment, masked fields, custom CRUD actions). Read before touching backend/src/Controller/Admin, backend/src/Security/Admin, or anything under /admin.
metadata:
  type: project
---

Implemented on `feature/backoffice-foundation` (docs/specs/2026-08-21-backoffice-foundation.md),
building on [[project_symfony_skeleton]], [[project_auth_and_accounts]] and
[[project_concert_domain_api]]. EasyAdmin 5.5 + scheb/2fa 8.x on Symfony 8.1/PHP 8.4 install cleanly
(R-1 resolved) but several non-obvious wiring gaps cost real debugging time.

**`doctrine/doctrine-bundle` 3.x dropped the `doctrine.event_subscriber` tag entirely**, even for a
class implementing `Doctrine\Common\EventSubscriber`. Each Doctrine event must be tagged
individually: `tags: [{name: 'doctrine.event_listener', event: 'preUpdate'}, ...]` in services.yaml,
or the listener is simply never registered on the EventManager (no error, it just silently never
fires). See `App\EventSubscriber\AuditLogAppendOnlySubscriber`'s services.yaml entry.

**Doctrine ORM (this version) dispatches `preRemove` synchronously from `EntityManager::remove()`
itself**, not deferred to `flush()`, for a root entity with no cascading associations — a test
expecting the exception on `flush()` must instead wrap the `remove()` call.

**scheb/2fa's TOTP and backup-code providers are disabled by default** (`canBeEnabled()`) —
declaring `security_tokens` in `scheb_two_factor.yaml` (the Flex recipe default) does **not** turn
2FA on. Without explicit `totp: {enabled: true}` and `backup_codes: {enabled: true}` blocks, every
login silently completes with password alone, no error, no log — this is the single easiest way to
ship 2FA that looks wired but isn't. Verify with a full curl/browser login, not just "the config file
has security_tokens in it".

**scheb/2fa's `TwoFactorAccessListener` runs *inside* the firewall's own listener stack** (the same
`kernel.request` priority slot Symfony's `Firewall` class occupies), so a plain external
`kernel.request` subscriber — at any priority — cannot run early enough to override the redirect
target it produces when 2FA is required. The supported seam is replacing the
`two_factor.authentication_required_handler` service (implementing scheb's own
`Scheb\TwoFactorBundle\Security\Http\Authentication\AuthenticationRequiredHandlerInterface`, **not**
the similarly-named Symfony core one — wrong namespace fails container compilation with a confusing
"class not found while loading" error). See `App\Security\Admin\
ForceEnrollmentAuthenticationRequiredHandler` — this is how D-49's "no secret → enrollment only" is
actually enforced; an external subscriber is only a narrow second net for direct navigation to the
normal 2FA form.

**A custom 2FA "user" wrapper object must implement `__sleep()` to exclude injected services.**
`Symfony\Component\Security\Http\Firewall\ContextListener` serializes the security token — and
therefore whatever object `getUser()` returns — into the session at the end of *every* request on a
session-based firewall, not only during login. If that wrapper (here, `App\Security\Admin\AdminUser`)
holds live services like `EntityManagerInterface` as constructor-injected properties, PHP's
`serialize()` throws ("Serialization of ... is not allowed") the moment any Doctrine proxy/metadata
resolver is reachable from the object graph. Fix: `__sleep()` returning only the wrapped entity
(`['user']`) — the *next* request always reconstructs a fresh, fully-wired wrapper via the user
provider's `refreshUser()`, so the stale deserialized object never needs its services again.

**Symfony's stateless "same-origin" CSRF scheme (`security.csrf_protection.stateless_token_ids`,
`SameOriginCsrfTokenManager`) makes `csrf_token($id)` return the literal *cookie name* string
(default `"csrf-token"`) as the token value** — it is not a per-request random token. Validity is
decided by the request's `Origin`/`Referer` header matching the app's own scheme+host (or a real
double-submit cookie for JS-driven clients), never by comparing against a stored value. A test
(`KernelBrowser` or `curl`) posting to a form protected this way must send a matching `Origin` header
and can hardcode `_csrf_token=csrf-token` — there is nothing to scrape from the HTML. See
`App\Tests\Functional\Admin\AdminWebTestCase`'s docblock.

**EasyAdmin's stock `crud/field/text.html.twig` renders `title="{{ field.value }}"` — the field's
*raw*, pre-`formatValue()` value — as a hover tooltip, unconditionally.** `formatValue()` alone only
changes `field.formattedValue`; the raw value still leaks into that `title` attribute regardless.
`App\Field\MaskedEmailField` must use a custom `setTemplatePath()` template that omits it. The same
category of leak also showed up in EasyAdmin's own dashboard user-menu widget (calls
`getUserIdentifier()`/`__toString()` on the logged-in user directly, ignoring any CRUD field
allowlist) — override `AbstractDashboardController::configureUserMenu()` to mask it too. Treat "does
a raw value reach the DOM anywhere, not just in the visible text" as the actual AC-10.3-shaped
question, not just "is the displayed text masked".

**A custom EasyAdmin CRUD controller action (`Action::new(...)->linkToCrudAction('methodName')`)
requires a `#[EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute]` attribute on that method**, or
EasyAdmin throws at render time ("used as a custom CRUD action but it is missing the #[AdminRoute]
attribute"). `EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem::linkTo()` (not `linkToCrud()`, which
doesn't exist in 5.5) takes `(controllerFqcn, label, icon)`, and its return type / the
`configureMenuItems()` iterable type comes from `EasyCorp\Bundle\EasyAdminBundle\Contracts\Menu\
MenuItemInterface` — **not** `Contracts\Config\MenuItemInterface`, which doesn't exist and produces a
PHPStan `class.notFound` plus cascading `generator.valueType` errors that look unrelated to the real
cause.

**`nelmio_cors.yaml`'s `paths: '^/': null` matches every path**, not just `/api` — this predates the
backoffice feature and would have silently handed `/admin` the same `allow_credentials: true`
cross-origin grant as the API the moment the admin firewall started answering requests there
(AC-11.5). Fixed to `paths: {'^/api': null}`. Any future path added outside `/api` needs the same
check.

**`KernelBrowser` reboots the kernel before each `$client->request()` call** — an entity fetched from
`static::getContainer()->get('doctrine')->getManager()` *before* a request becomes unmanaged by the
*new* container's entity manager after that request. `EntityManager::find()`/`getRepository()->find()`
against a freshly re-fetched `$em` works fine regardless (it queries anew), but
`EntityManager::refresh($staleEntity)` throws `ORMInvalidArgumentException` ("not managed"). Always
re-fetch `$em` (and re-`find()` the entity) after any `$client->request()` call, not just before the
first one.

**PHP's default CLI `memory_limit` (128M in this project's container) is not enough to run the full
`vendor/bin/phpunit` suite once ~20 admin functional tests were added** (each does several
kernel-reboot-triggered container compilations via `KernelBrowser`). Fails mid-run with "Allowed
memory size exhausted" in `PhpDumper`/`Crawler`, not in any specific test. Run locally with
`php -d memory_limit=512M vendor/bin/phpunit`; `vendor/bin/phpstan analyse` needed
`--memory-limit=1G` for the same reason. CI's `setup-php` action typically sets a much higher default
already, so this may not reproduce there — but if `composer test`/CI ever starts hitting it, this is
the first thing to check, not a sign of a leak.

**PHPStan's Symfony extension resolves `ContainerInterface::get('some.service.id')`'s concrete
return type from the compiled container XML** (`containerXmlPath` in `phpstan.neon.dist`) when the
id matches a real service — so a redundant `\assert($x instanceof Y)` right after `->get('y.id')`
gets flagged `instanceof.alwaysTrue`/`function.alreadyNarrowedType`. Safe to just delete these asserts
once the container already proves the type; don't add them defensively for a `get()` call with a
literal, real service id.

See [[project_symfony_skeleton]] for the base app conventions this feature builds on, and
[[project_auth_and_accounts]]/[[project_concert_domain_api]] for the earlier custom-authenticator and
DTO-provider patterns this one's `AdminUserProvider`/`AdminUser` continue.
