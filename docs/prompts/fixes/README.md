# Fix prompts

Ready-to-run prompts for work that is **not** a backlog feature: unfinished scope from a prompt that
already shipped, environment breakage, and accumulated debt.

These are deliberately **outside the numbered backlog** in `../`. That sequence is strictly ordered
and each entry's `Depends on:` line assumes nothing is inserted between numbers; a fix that arrives
after prompt 14 has no natural number and would corrupt that reading. Work these by priority instead.

## How to use one

Open the file, paste its fenced prompt block into Claude Code. Each is self-contained: it names its
own branch, the documents to read, what to build, and how to verify.

Delete a file once its work is merged.

## Open

| Prompt | Priority | Branch | Blocks |
|---|---|---|---|
| [A — Pin the mailpit image](a-pin-mailpit-image.md) | **High** | `bugfix/pin-mailpit-image` | `docker compose up` on any fresh clone |
| [B — Missing failure-mode tests](b-playlist-failure-mode-tests.md) | **High** | prompt 14's branch | Prompt 14 acceptance criteria |
| [C — Backoffice screens](c-playlist-backoffice-screens.md) | Medium | prompt 14's branch | Prompt 14 acceptance criteria |
| [D — Matching-quality harness](d-matching-quality-harness.md) | Medium | prompt 14's branch | Regression safety for every future matching change |
| [E — setlist.fm test debt](e-setlist-tests-phpstan.md) | Low | `bugfix/setlist-tests-phpstan` | Nothing; static-analysis hygiene |

B, C and D are unfinished **prompt 14** scope. If `feature/playlist-fast-mode-backend` is still
open, they belong on it; if it has been merged, branch each off `master`. Each prompt says so.

## Context

Recorded 2026-08-23, after prompt 14's implementation pass. At that point the branch stood at
397 tests passing, phpstan clean in `src/`, with the engine and pipeline built and the proof layer —
quality gate, failure-mode tests, backoffice observability — still missing.
