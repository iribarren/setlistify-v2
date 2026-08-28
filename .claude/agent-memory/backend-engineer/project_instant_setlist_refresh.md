---
name: project_instant_setlist_refresh
description: A pre-existing SetlistCacheEntry double-insert bug found while implementing force-live refresh, the classifySearchResult refactor pattern, and phpstan gotchas (generic Closure return, nullsafe-on-left-of-??) hit on this feature. Read before touching SetlistCache, BandIdentityResolver, or another Redis-backed coordinator with a generic withLock() helper.
metadata:
  type: project
---

Implemented on `feature/instant-setlist-refresh`
(docs/specs/2026-08-27-instant-setlist-refresh.md, D-254–D-280), building on
[[project_streaming_port_and_linking]]'s D-58 door-test pattern and the playlist pipeline's
async trigger/poll shape (`StartGenerationProcessor`/`BuildPlaylistHandler`).

**Found and fixed a real, pre-existing bug in `SetlistCache::fetch()`, unrelated to this feature's
own additions**: when a cache entry already exists for a `cacheKey` and is stale (the ordinary
"volatile entry past `staleAfter`, re-fetch" path — `artist.search`, `artist.setlists` page 1), the
code built `new SetlistCacheEntry(...)` and `save()`d it — inserting a SECOND row under the same
unique `cache_key` (`uniq_setlist_cache_key`), throwing `UniqueConstraintViolationException`. This
was previously unreachable in the test suite (fast test runs rarely hit a genuinely-stale-but-
present entry), but instant setlist refresh's force-live path (which deliberately re-fetches
despite a *fresh* entry) makes it certain. Fixed by adding `SetlistCacheEntry::refresh(payload,
fetchedAt, staleAfter, httpStatus)` (a mutator on an otherwise-immutable entity) and having
`SetlistCache::fetch()` call it on the existing managed entity (`$entry->refresh(...); save($entry)`)
instead of constructing a new one, whenever `$entry` is non-null — covers both the pre-existing
stale-refetch path and the new force-live path with one code change. Caught by a test that queues
two responses and does an ordinary fetch then a force-live fetch back to back — worth remembering
this shape (populate-then-refetch-same-key) as a good bug-finding pattern for any cache layer.

**Pattern for "add a force/bypass variant without duplicating classification logic"**:
`BandIdentityResolver::ensureResolved()`'s existing early-return guard must NOT be touched (AC-2.3-
shaped constraint, common in this codebase — see also `EmailVerifiedVoter`'s "same generic shape,
never modify the base"). Solution: extract the post-guard body (candidate parsing + exact-match
classification) into a private `classifySearchResult(Band, CachedFetch): BandResolutionOutcome`;
`ensureResolved()` calls `gateway->searchArtist()` then `classifySearchResult()`; the new
`forceResolve()` resets the band, calls `gateway->refreshArtistSearch()` (a force-live sibling that
skips `SetlistCache`'s Redis+Postgres freshness checks but keeps the write path, the lock and the
budget gate), then also calls `classifySearchResult()`. One classification path, two fetch paths —
satisfies "classification logic exists in exactly one place" even though the literal spec wording
("delegates to ensureResolved()") doesn't describe this refactor precisely; noted as a judgment call
in the PR.

**phpstan level 9 gotchas hit on this feature, all fixable, none pre-existing**:
- A method that returns whatever a `\Closure` argument returns (`withBandLock(int, \Closure $fn):
  mixed`) needs `@template T` / `@param \Closure(): T $fn` / `@return T` on the method — without it,
  every caller's return type collapses to `mixed` and phpstan flags "method X should return Y but
  returns mixed" at each call site, not at the generic method itself.
- `$nullable?->prop ?? $default` inside a constructor-argument list is flagged as "nullsafe on left
  side of ?? is unnecessary, use -> instead" by this repo's phpstan config even when the object
  really can be null — phpstan's fix suggestion is unsafe to follow literally (plain `->` would NPE).
  The clean fix is `null !== $x ? $x->prop : $default`, not `$x->prop ?? $default`.
- A `Voter::voteOnAttribute(mixed $subject, ...)` body should access `$subject->method()` directly
  with NO `instanceof`/`assert` guard at all when the class declares `@extends Voter<string, User>`
  — phpstan already narrows `$subject` from that generic, so any explicit check is flagged as
  "always true" (confirmed by comparing against the pre-existing `EmailVerifiedVoter`, which has no
  guard and is phpstan-clean). Same for a `ProcessorInterface<InputType, OutputType>`-annotated
  processor's `process(mixed $data, ...)` — access `$data->field` directly, no instanceof check
  (confirmed against `StartGenerationProcessor`'s precedent, which uses a bare `\assert(null !==
  $data->concertId)` on a scalar, not a type-narrowing assert).
- `SetlistRefreshRecord::fromArray()` (a `Redis::get()` → `json_decode` → value-object hydration,
  the same shape as `TrackResolutionStore`/`SetlistCacheMetrics`) needed the same `asString`/
  `asStringOrNull` narrowing-helper pattern `SetlistNormalizer` already uses for raw JSON — every
  `(string) $mixedValue` cast is a phpstan error at level 9 even though it's runtime-safe.

**Reused `ConcertOwnerExtension` without modifying it or adding a new query-extension class**, per
the spec's own constraint (D-266): `App\State\BandOwnershipChecker` builds a `ConcertRepository`
query joining `ConcertBand`, then calls `$ownerExtension->applyToCollection(...)` on it directly —
same pattern `ConcertLocator` uses for `applyToItem()`. This is the shape to copy for "does the
current user own something connected to X via a join", not a new `QueryCollectionExtensionInterface`
implementor.

**Env vars added to a Symfony app need THREE places, not two, or tests fail with
`EnvNotFoundException`**: `.env.example` (docs/checked-in), `.env.local` (this environment's actual
runtime value, gitignored — `docker compose` reads it), AND `phpunit.xml.dist`'s `<env ...
force="true"/>` block (the `test` environment pins every external-service-shaped env var explicitly
so the default suite never depends on `.env.local`, per the existing setlist.fm block's own
comment). Missing the third one only surfaces when a functional/integration test actually boots the
container service that reads the var — a `cache:clear --env=test` alone doesn't catch it.

See [[project_streaming_port_and_linking]] for the "force-live siblings on the one door" pattern
this feature's `SetlistGateway::refreshArtistSearch()`/`refreshArtistSetlistsPageOne()` follows, and
[[project_playlist_fast_mode_backend]] for the async trigger/`202`/poll/`Retry-After` request-
attribute-driven status-override shape (`PlaylistResponseHeadersSubscriber`) this feature's
`SetlistRefreshResponseHeadersSubscriber` copies verbatim.
