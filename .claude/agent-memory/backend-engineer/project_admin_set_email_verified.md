---
name: project_admin_set_email_verified
description: Gotchas from implementing the admin manual-email-verification action (frozen-clock testing, a spec/code contradiction in EmailVerificationService::confirm()'s return contract). Read before touching UserCrudController's verify action, EmailVerificationService, or EmailVerificationConfirmProcessor.
metadata:
  type: project
---

Implemented on `feature/admin-set-email-verified`
(docs/specs/2026-08-27-admin-set-email-verified.md, D-248–D-253), building on
[[project_backoffice_foundation]] and [[project_auth_and_accounts]].

**`Psr\Clock\ClockInterface` in this app aliases to the `clock` service
(`Symfony\Component\Clock\Clock`), which — when constructed with no explicit inner clock (the
default autowired instance) — delegates to the static `Clock::get()`/`Clock::set()` global.** That
means `Symfony\Component\Clock\Test\ClockSensitiveTrait::mockTime()` (call it in a test, e.g.
`self::mockTime($frozenDateTimeImmutable)`) freezes time for *every* injected `ClockInterface`
consumer across the whole process, including across `KernelBrowser`'s per-request kernel reboots,
because the frozen clock lives in a static property, not the container. This is the way to test any
new "stamp with the injected clock" admin action without constructing the service by hand. Verified
via `bin/console debug:container --env=test | grep -i clock`. No `MockClock` service override needed
in `config/services.yaml`.

**A spec's stated behavior can contradict itself once you trace an existing caller — check before
implementing literally.** D-252 asked for `EmailVerificationService::confirm()`'s return value to
mean "true if a previously-unverified user was just verified" (matching its pre-existing but
previously-false docblock), *and* asked that "the HTTP response for a valid token stays the same
either way" (a valid token against an already-verified user still yields the existing 204). But
`App\State\Processor\EmailVerificationConfirmProcessor` throws its generic 400 exactly when
`confirm()` returns `false` — so literally following the return-value instruction would make a
valid-but-already-verified token wrongly 400, breaking the same spec's own explicit HTTP-response
requirement and its own test-plan acceptance criterion. Resolved by keeping `confirm()`'s return
contract as "was a structurally valid, unexpired, unused token found and consumed" (unchanged from
before — and the same convention `PasswordResetService::confirm()` already uses), while still fixing
the actual behavioral bug D-252 cared about (guarding `markEmailVerified()` so a stale token can't
silently overwrite an admin-set timestamp). Documented this deviation explicitly in the method's
docblock rather than touching `EmailVerificationConfirmProcessor.php` (not in the spec's "Files
touched" table) or inventing a new domain exception type (no precedent for one in
`Service/Security/`). **When a spec's stated return-value semantics for a shared boolean contract
conflict with an existing caller's use of that same boolean for something else (here: HTTP status
selection), trust the caller's contract and the spec's own acceptance criteria over the literal
return-value wording, and flag the conflict rather than silently picking one.**

**EasyAdmin's `Action::displayIf(callable $callable)` takes the entity instance directly** (the
callable's single argument), not an `AdminContext` or DTO — e.g.
`Action::new('verifyEmail', 'Verify email')->displayIf(static fn (User $u): bool =>
!$u->isEmailVerified())`. Confirmed by reading `vendor/easycorp/easyadmin-bundle/src/Config/Action.php`
before use; no existing controller in this codebase used a conditional action yet, so there was no
local precedent to copy.

**The container's JWT keypair can go stale/mismatched between `config/jwt/private.pem` and
`public.pem`** (observed: every login-touching functional test failed with "An error occurred while
trying to encode the JWT token... verify your configuration (private key/passphrase)", including
tests that touch none of the files in this branch — `LoginTest.php` alone reproduced it on a clean
checkout). Fixed with `bin/console lexik:jwt:generate-keypair --overwrite --no-interaction` inside
the container. Not caused by any app code change — check this first if a fresh session's test run
shows *every* auth-touching test failing with this exact JWT message, before assuming a real
regression.

See [[project_auth_and_accounts]] for the email-verification token flow this extends, and
[[project_backoffice_foundation]] for the confirm→POST→audit action pattern this action copies.
