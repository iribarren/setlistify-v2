---
name: project_streaming_port_and_linking
description: Non-obvious gotchas and deliberate interface deviations from implementing the streaming port, Spotify adapter, and account linking. Read before touching Service/Streaming/, Service/Provider/, StreamingAccount, or adding a second provider (prompt 18).
metadata:
  type: project
---

Implemented on `feature/streaming-port-and-account-linking`
(docs/specs/2026-08-22-streaming-port-and-account-linking.md, D-71..D-88), building on
[[project_symfony_skeleton]], [[project_auth_and_accounts]], [[project_concert_domain_api]] and
[[project_backoffice_foundation]].

**`StreamingProviderInterface` deviates from the literal `docs/architecture.md` §4 sketch in two
places, both documented in the interface's own docblock — read that before assuming the sketch is
gospel.** (1) `authorizationUrl()`/`exchangeCode()` each gained one optional, nullable parameter
(`$codeChallenge`/`$codeVerifier`) beyond the sketch's two-argument signatures — PKCE (AC-1.2) needs
a channel through the frozen interface and the sketch's signatures have none; D-71 only freezes the
*method count* (nine), not each method's arity, so this was judged the honest fix over deriving a
verifier statelessly inside an adapter instance (unsafe under FrankenPHP's persistent workers). (2)
`ProviderTokens` gained nullable `providerAccountId`/`providerDisplayName` fields — AC-1.4 requires
fetching provider identity as part of the OAuth exchange, and the frozen interface has no identity
method; carrying it on the token value object avoided a tenth port method. If a future spec review
disagrees with either call, both are isolated (one interface, one value object) and easy to revert.

**D-81/AC-3.2's "adapter attempts revocation before deletion" is a no-op in this branch, documented
in `App\State\Processor\StreamingAccountUnlinkProcessor`'s docblock.** The frozen 9-method port has
no revoke method, and Spotify itself exposes none to call — adding a tenth method for a capability
zero current adapters implement would pre-empt D-71's "capability value object" escape valve for
when a provider that DOES support revocation arrives (prompt 18/YouTube might). Unlink just deletes;
the honest "you must also remove Setlistify in your Spotify settings" copy is frontend work, not yet
built (this branch is backend-only, `docs/prompts/10-...`).

**`Doctrine\DBAL\Types\Type` instances are true singletons instantiated by `Type::getType()` with NO
constructor arguments — a real DBAL limitation, not a design choice.** `App\Doctrine\Type\
EncryptedStringType` cannot receive `TokenCipher` through normal DI. It lazily builds one from real
process env vars (`getenv('TOKEN_ENCRYPTION_KEY')` etc, via `TokenCipher::fromEnvironment()`) on
first use, cached in a static property; tests override via `EncryptedStringType::configure($cipher)`
/ `::reset()`. This is the established pattern in this codebase now for any future Doctrine custom
type that needs configuration (there was no prior example — `TotpSecretEncryptor` for the admin's
TOTP secret is a plain service, not a Doctrine type, because that entity property was never
auto-encrypted transparently the way `StreamingAccount`'s token columns are).

**The OAuth callback (`GET /api/streaming/{provider}/callback`) is a plain `#[Route]` Symfony
controller (`App\Controller\StreamingCallbackController`), not an API Platform resource** — it's an
unauthenticated bare browser redirect (no JWT exists on that hop) whose response is an HTTP redirect,
not JSON. It therefore does NOT appear in the OpenAPI spec, deliberately (same reasoning as the admin
backoffice's session routes being outside the contract). Verified via `api:openapi:export` — only
the four JSON operations (`/streaming/link`, `/streaming/link-results/{ref}`,
`/streaming/accounts(/{id})`) appear.

**`state`/link-result Redis records use a hand-rolled atomic GET+DEL via `$redis->eval()` (a tiny Lua
script), not phpredis's `getDel()`.** Needed for genuine single-use semantics under concurrent
replay attempts (AC-8.2/AC-8.7) — a plain `get()` then `del()` has a race window. Pattern lives in
`App\Service\Streaming\Link\PendingLinkStore`/`LinkResultStore`; reuse it for any future single-use
Redis token rather than reinventing.

**The callback is structurally un-forgeable rather than checked against a "current session"
(AC-8.4).** There is no session/JWT on the callback request at all — the owner of a completed link
is, by construction, whichever user's `start()` call produced the `state` being completed, since
`state` is bound to exactly one user id at creation and consumed exactly once. Don't go looking for
an explicit "does state.userId match session.userId" comparison in `LinkFlowService` — it doesn't
exist because there's nothing to compare against; the two-user non-collision test
(`LinkFlowServiceTest::testTwoUsersPendingLinksNeverCollide`) proves the property behavior-first
instead.

**`StreamingTokenManager`'s concurrent-single-flight test (AC-4.3) is NOT a real multi-process/OS
concurrency test** — PHPUnit tests run single-threaded in one process, so
`testASecondCallAfterTheFirstHasAlreadyRefreshedDoesNotRefreshAgain` instead proves the
double-check-after-acquiring-the-lock mechanism deterministically via two sequential calls in one
process. `symfony/lock`'s `FlockStore` genuinely works cross-process (file-based), so a real
multi-process test IS possible if this guarantee is ever doubted hard enough to justify the cost —
just wasn't done here given the effort/value tradeoff.

**Registering a test-only tagged service**: a class under `tests/` (not `src/`) that needs to join a
`!tagged_iterator` picked up by a `src/`-registered service (here, `App\Service\Streaming\
StreamingProviderLocator`'s `app.streaming_provider` tag) is registered in `config/services.yaml`'s
`when@test: services:` block, tagged explicitly (autowire/autoconfigure defaults from the main
`_defaults:` block are NOT guaranteed to apply there — didn't matter for
`TestDoubleStreamingProvider` since it has no constructor deps, but tag explicitly either way).

**Running `php-cs-fixer fix` (not `--dry-run`) across the whole repo will happily "fix" files outside
your diff** — one pass demoted a working `/** @var list<string> */` inline docblock in a pre-existing,
unrelated file (`TwoFactorEnrollmentController.php`) to a plain `/* @var */` comment (because
php-cs-fixer's `phpdoc_no_useless_inline_doc_block` rule considered it "not immediately followed by
the exact variable's own assignment"), which silently un-narrowed the type and produced two NEW
PHPStan errors in a file this branch never touched. Caught by running PHPStan on the whole repo
before committing and diffing against the pre-existing error count; fixed by `git checkout --` on
that one file rather than trying to appease both tools in code outside this feature's scope. Lesson:
after a repo-wide `cs-fixer fix`, diff `git status` against your intended file list before staging,
and re-run PHPStan project-wide (not just on your new files) to catch this class of collateral
damage.

**The architecture-isolation test (`SpotifySymbolIsolationTest`, AC-9.4) is a literal, case-sensitive
substring scan for `"Spotify"` over `.php` files in `src/` — it does NOT understand comments.**
Referencing the real provider by name in a docblock/comment anywhere outside
`src/Service/Streaming/Spotify/` fails the build exactly like a real symbol reference would (hit this
three times while writing docblocks — in `StreamingProviderInterface`, `QuotaExhaustedException`,
`StreamingAccountUnlinkProcessor`, and `SpotifyHttpClient`'s own file when it referenced
`SetlistFmClient` by name and tripped the *setlist.fm* gateway isolation test instead). Write
"the provider"/"the reference provider" in prose outside that directory; the literal string `'spotify'`
as *data* (entity column values, fixture files, route paths) is fine since the test is
case-sensitive and only matches the capitalized symbol form.

**New `Version20260822140000` migration uses `id INT GENERATED BY DEFAULT AS IDENTITY`, not
`SERIAL`** — matches `concerts`' (most recent, "correct") convention rather than the older
`setlist_cache`/`setlists`/`songs` migrations' `SERIAL`, which is itself pre-existing drift in this
repo (`doctrine:schema:validate` already reports it against master, unrelated to this branch). Also:
the partial-unique-index-on-status representation gap (`WHERE status <> 'connected'`) shows up as
permanent `doctrine:schema:update --dump-sql` drift, same as the pre-existing
`uniq_bands_setlistfm_mbid` partial index — Doctrine's ORM attribute mapping cannot express a partial
index's `WHERE` clause, so this is expected and matches existing precedent, not a bug to chase.

See [[project_symfony_skeleton]], [[project_auth_and_accounts]], [[project_concert_domain_api]] and
[[project_backoffice_foundation]] for the earlier conventions this feature continues (health-check
shape, RateLimiterGuard fail-closed posture, ConcertOwnerExtension's cross-owner-404 pattern copied
verbatim for `StreamingAccount`, AuditLogger as the one admin-write path).
