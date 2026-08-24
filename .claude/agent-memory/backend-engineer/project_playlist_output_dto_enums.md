---
name: project_playlist_output_dto_enums
description: Backed-enum DTO properties, OpenAPI enum-schema container caching, and phpstan/phpunit memory limits in this repo's backend container. Read before typing another ApiResource output DTO field or trusting api:openapi:export output.
metadata:
  type: project
---

Fixed on `bugfix/playlist-report-enum-typing`: `PlaylistGenerationJobOutput`, `PlaylistOutput`,
`PlaylistTrackOutput`, `ReportEntryOutput` (`backend/src/ApiResource/Playlist/`) had backed-enum
domain fields (`JobState`, `BlockedReason`, `FailureReason`, `ResultKind`, `TrackOutcome`,
`ReportCode`) flattened to `string`/`?string` by their mappers calling `->value`, which downgrades
the generated OpenAPI schema from a literal `enum` to a bare `"type": "string"` — and therefore the
frontend's `openapi-typescript` client type too.

**Fix pattern for an output DTO field backed by a PHP backed enum**: declare the DTO property as the
enum type itself (not `string`), and in the mapper pass the enum value straight through — drop the
`->value` call. Symfony's serializer has a native `BackedEnumNormalizer`, so the actual JSON payload
is unchanged (still serializes to the scalar backing value) — this is a type-only fix, verified by
running the existing `PlaylistGenerationApiTest` (asserts `$data['state']`/`$data['blockedReason']`
as plain strings) unchanged and green.

**One field needed a reverse conversion, not just dropping `->value`**: `Playlist::$reportSummary` is
a Doctrine `json` column storing `['code' => string, 'params' => array]` — the *entity* only ever
held the raw string (populated from `ReportCode::X->value` at the two call sites,
`SetlistSelectionStage`/`InsertionStage`), never a `ReportCode` instance. So `ReportEntryOutput`'s
mapper needed `ReportCode::from($entry['code'])`, not a `->value` removal, to get an enum instance
before construction. Check whether the source field is already entity-typed as the enum
(`PlaylistTrack::$reasonCode` was — `?ReportCode`, straightforward) vs. stored as a raw scalar in
JSON/array form before assuming "just delete `->value`" is the whole fix.

**`api:openapi:export` can silently serve a stale schema from the container cache** — same shape as
[[project_concert_domain_api]]'s debug:false container staleness note, but this hit even the default
`dev` container. After changing DTO property types, the export command must be preceded by
`cache:clear` or the `enum` key simply won't appear in the output, with no error — looks exactly like
"the fix didn't work" if you don't know to clear cache first.

**Both `phpstan analyse` and `phpunit` need `--memory-limit=512M`/`-d memory_limit=1G` in this
container** — the default 128M CLI limit isn't enough for either tool on this codebase's size and
crashes with a fatal OOM (phpstan: parallel workers die one by one; phpunit: dies mid-first-test).
Not a regression from this fix — reproduces on a clean `git stash` too. Use
`php -d memory_limit=1G vendor/bin/phpunit` and `vendor/bin/phpstan analyse --memory-limit=512M`
inside `docker compose exec -T backend`.

**Pre-existing phpstan level 9 debt, unrelated to enum/DTO work** (58 errors as of this fix,
confirmed present on `master` via `git stash`): `tests/Matching/MatchingQualityHarnessTest.php`
(`mixed`-typed fixture data cast without narrowing) and an `assert()`-always-true /
always-true-instanceof pair in `tests/Functional/Admin/Playlist{,GenerationJob}CrudControllerTest.php`.
Don't treat these as caused by a DTO/enum change — verify against `master` before assuming a phpstan
error is new.

`backend/openapi.json` (the checked-out snapshot `api:openapi:export --output=` writes by
convention, per README) is gitignored (`.gitignore:26`) — regenerating it locally is fine but it is
never committed.

See [[project_concert_domain_api]] for the sibling container-caching gotcha this one echoes, and
[[project_playlist_fast_mode_backend]] for the domain model these DTOs wrap.
