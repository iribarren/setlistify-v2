---
name: project_playlist_generation_backoffice
description: EasyAdmin generic-Field auto-guessing pitfall (silently discards formatValue), DBAL raw-value cast safety, and percentile/rate fixture gotchas for the playlist-generation dashboard panel. Read before adding a computed/enum/json display column to any EasyAdmin CRUD controller, or touching PlaylistDashboardMetrics.
metadata:
  type: project
---

Implemented on `bugfix/playlist-generation-backoffice` (spec `2026-08-23-spike-playlist-pipeline.md`
§8, D-141/D-142), filling in backoffice scope prompt 14 deferred (see
[[project_playlist_fast_mode_backend]]). Added `PlaylistGenerationJobCrudController`,
`PlaylistCrudController`, `App\Service\Playlist\PlaylistDashboardMetrics`, and the dashboard's
"Playlist generation (last 7 days)" panel — all read-only, no write/retry action anywhere (D-142).

**`EasyCorp\Bundle\EasyAdminBundle\Field\Field::new('aRealMappedPropertyName')` silently discards
`formatValue()` for that field.** `FieldCollection::processFields()` unconditionally stamps a bare
`Field` instance's `fieldFqcn` to `Field::class`, and `FieldFactory::replaceGenericFieldsWithSpecificFields()`
then auto-guesses a CONCRETE field class from the Doctrine column type for any field whose property
name matches a real mapped property/association — a `string` (even an enum-backed) column becomes
`TextField`, a `json` column becomes `ArrayField`. `createSpecificFieldFromGenericField()` copies
over the label and template path but **never the `formatValueCallable`**, so a
`Field::new('mode')->formatValue(...)` on a real property is dead code — it "coincidentally" renders
right for a non-null backed enum only because `TextConfigurator` independently converts
`UnitEnum::$value`/`$name` itself, but breaks silently (blank output) for a null value, and for a
`json` array column `ArrayField`'s own index-page formatter does `u(', ')->join($values)`, which
stringifies each element and produces a literal `"Array"` for a JSON array-of-arrays. **Fix: name
the field something that matches NO real property/getter** (e.g. `modeLabel`, `stageTimingsPretty`,
`tracksTable`) — `replaceGenericFieldsWithSpecificFields()` explicitly skips its own guess for an
unmapped property ("this is a virtual field, so we can't autoconfigure it"), so the field keeps
`fieldFqcn = null` and no configurator ever touches it, leaving `formatValue()` as the only source of
truth. A virtual field named this way still needs `->setTemplatePath('admin/field/raw_html.html.twig')`
set explicitly (see that template's own docblock) — `CommonPreConfigurator::buildTemplatePathOption()`
renders an "Inaccessible" badge for an unmapped property BEFORE `formatValue()` ever runs, unless a
template path is already set. That combination (virtual name + explicit raw-HTML template +
`formatValue()`) is the only combination that reliably renders a computed enum/json/cross-entity
column in this EasyAdmin version — this generalizes past this feature to any future computed CRUD
column.

**`Doctrine\DBAL\Connection::fetchAllAssociative()`/`fetchOne()`/`fetchFirstColumn()` return
`array<string, mixed>`/`mixed` — PHPStan level 9 flags every direct `(int)`/`(string)` cast on a row
value as `cast.int`/`cast.string`.** Route every raw DBAL row value through a small
`is_numeric($v) ? (int) $v : 0` / `is_scalar($v) ? (string) $v : ''` helper (mirrors
`DashboardController::toInt()`'s existing pattern) rather than casting `mixed` directly.

**`PlaylistGenerationJob::freezeCounters()` does NOT set `songsTotal`** (that's `setSongsTotal()`,
called earlier in the real pipeline) — a metrics-fixture job that only calls `freezeCounters(...)`
without also calling `setSongsTotal(...)` first has `songsTotal = 0`, making the match-rate
denominator (`songsTotal - skippedCount`) `<= 0` and silently excluded from `meanMatchRate`
(`null` result, no error) — easy to misdiagnose as a query bug. Always call `setSongsTotal()` in any
job fixture that also calls `freezeCounters()`.

**The `uniq_live_generation` partial unique index (`(concert_id, provider_key)` WHERE state IN
`queued, resolving_setlist, awaiting_setlist_choice, matching, awaiting_version_choice, building,
blocked`) treats `blocked` as a "live" state**, not just the obviously-active ones — two fixture jobs
both directly set to `Blocked` (via `setStateInternal()`, bypassing `JobStateMachine`) for the SAME
concert+provider collide on this index exactly like two genuinely concurrent live jobs would. Give
each simultaneously-live-state fixture row (`queued`/`blocked`/etc.) its own `Concert`; only
terminal-state rows (`completed`/`failed`/`expired`/`cancelled`) are free to share one.

**Calling `PlaylistGenerationJob::setStateInternal()`/`block()`/`fail()`/`freezeCounters()` directly
in a test (bypassing `JobStateMachine`) is fine and already done elsewhere** — D-159's "only
`JobStateMachine` may call `setStateInternal()`" rule (`JobStateMachineIsOnlyStateWriterTest`) is a
`src/`-only static file scan; it does not (and should not) reach into `tests/`.

See [[project_playlist_fast_mode_backend]] for the pipeline/entity shape these screens read, and
[[project_backoffice_provider_configuration]]/[[project_backoffice_foundation]] for the
`AbstractAdminCrudController`/masked-email/audit conventions this feature's two read-only controllers
continue (no writer service needed here — there's nothing to write).
