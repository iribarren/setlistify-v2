# Setlistify — Workspace

<!-- Installed by setup-claude.sh — project type: fullstack · agents: project-manager-docs, devops-security-engineer, frontend-engineer, backend-engineer · tools: codegraph rtk -->

> Template. Replace every `<...>` placeholder and delete guidance you don't need.
> The **Mandatory Workflow**, **Branch Rules** and **Execution Policy** sections below are what the
> exported commands (`/feature`, `/bugfix`, `/pr`, `/spec`, `/test`, `/doc-check`) and hooks rely
> on — keep them roughly intact so the tooling behaves as designed.

## Overview
<One-line description of what this project is and who it's for.>

## Structure
<Monorepo layout — one row per package/app.>

| Directory | Component | Stack |
|-----------|-----------|-------|
| `<backend>/` | <name> | <e.g. REST API> |
| `<frontend>/` | <name> | <e.g. SPA> |

## Development

```bash
<how to start everything locally, e.g. docker compose up -d>
```

| Service | URL |
|---------|-----|
| Frontend | <http://localhost:PORT> |
| Backend API | <http://localhost:PORT/api/*> |

## Key Conventions
- All code (variables, functions, comments, DB fields) MUST be in English.
- If the project has a backend, data persistence and sensitive logic must live there, not in the client.
- Security is an MVP requirement, not a stretch goal.
- <Add project-specific conventions, domain glossary, naming rules here.>

## Tooling

<!-- tool:codegraph -->
### CodeGraph — query the code index before you read

The project is indexed into a knowledge graph of every symbol, edge and file, exposed through the
`mcp__codegraph__*` tools. Reads are sub-millisecond and the index follows edits within about a
second.

- **Consult it BEFORE writing or editing code**, not during.
- For "how does X work", "where is X", architecture or trace questions, answer **directly** with one
  `codegraph_explore` call — it returns the verbatim source of the relevant symbols grouped by file,
  so it is Read-equivalent and usually the only call needed. Do not spawn a search subagent or run
  your own grep-and-read loop for this; that repeats work the index already did.
- `codegraph_callers` / `codegraph_callees` / `codegraph_impact` answer "what calls this?" and "what
  would changing this break?".
- Fall back to `Read`/`Grep` only to confirm a detail CodeGraph did not cover.
- If results look stale, `codegraph sync` refreshes the index incrementally.

<!-- tool:rtk -->
### RTK — token-optimised CLI

`rtk` proxies common read-only commands and strips the noise from their output (60-90% fewer
tokens). A `PreToolUse` hook rewrites bare commands automatically, so most of the time this happens
without you doing anything.

- Prefer `rtk <cmd>` for inspection: `rtk git status`, `rtk grep …`, `rtk ls`, `rtk find …`,
  `rtk docker …`.
- Use `rtk proxy <cmd>` to bypass the filtering when you genuinely need raw output to debug.
- `rtk gain` reports the tokens saved so far.

## API Contract (fullstack / API + client)

> Keep this section only if the project has a backend API and a client that consumes it
> (fullstack, or an API paired with a separate frontend/mobile client). Delete it otherwise.

When a backend and a client grow together, the API contract is the coupling point — treat it as a
single source of truth so a change on one side surfaces on the other at build time, not in
production.

- **The OpenAPI/API spec is the single source of truth for endpoints.** `backend-engineer` updates
  the spec in the SAME change as any endpoint change (request/response shapes, status codes, auth,
  error format). Do NOT list endpoints in any README.
- **Generate the client, don't hand-write it.** The client's API types/SDK are generated from the
  spec (e.g. `openapi-typescript`, `orval`, `openapi-generator`). `frontend-engineer` /
  `mobile-engineer` consume the generated types — never redeclare request/response shapes by hand,
  so a breaking API change becomes a compile error in the client.
- **One feature, one spec, one branch.** A change that spans the API and the client is specified
  once (`docs/specs/…`), implemented on a single `feature/<name>` branch, and reviewed as one PR —
  never merged half-and-half across sides.
- **Regenerate before wiring up.** After the spec changes, regenerate the client types before
  editing client code that calls the new/changed endpoint.
- **Verify against the real integration.** Bring both sides up together (e.g. `docker compose up`)
  so `/test` and manual checks exercise the actual client↔API path, not mocks alone.

## Execution Policy
- NEVER run anything in a scratch/temporary directory (e.g. `/tmp/...`), and NEVER execute
  commands, tests, installs, or tooling outside the project folder.
- All commands MUST run inside the project tree, or inside the project's own containers
  (`docker compose exec ...`).
- If a constraint prevents running something in place (permissions on `node_modules`, an unsuitable
  container image, a missing dependency, a missing browser), STOP. Do not work around it with a
  scratch directory or an external location. Report the blocker and propose fixes (correct file
  ownership, adjust the image/service, add the dependency, change config) and wait for the user to
  decide.

## Mandatory Workflow

### New Feature Workflow
When the user requests a new feature or enhancement, ALWAYS follow this sequence:

1. **Specification first**: Delegate to the `project-manager-docs` agent to define the feature. The
   spec is saved in `docs/specs/YYYY-MM-DD-feature-name.md`. Must include: Overview, User Stories,
   Acceptance Criteria, Out of Scope, Dependencies.
2. **User approval**: Present the spec. Do NOT proceed until the user explicitly approves.
3. **Create feature branch**: `feature/<short-name>` from `<main-branch>` in the appropriate repo.
4. **Implement**: Use the appropriate agent(s). Writing the tests that cover the change is part of
   this step, done by the same agent — not a separate hand-off.
5. **Commit on the feature branch**: Never directly on `<main-branch>`.

### Bug Fix Workflow
When the user reports a bug:

1. **Diagnose**: Understand the bug.
2. **Create bugfix branch**: `bugfix/<short-name>` from `<main-branch>`.
3. **Fix and test**.
4. **Commit on the bugfix branch**.

### Branch Rules
- NEVER commit directly to `<main-branch>` (e.g. `master` or `main`).
- Branch naming: `feature/<name>` or `bugfix/<name>`, lowercase, hyphen-separated.
- If already on a feature/bugfix branch, continue on it.

> Note: the `check-branch.sh` hook enforces this by blocking source edits on the main branch.
> Adjust `MAIN_BRANCHES` in that hook if your default branch is not `master`/`main`.

### Documentation Update (mandatory before committing)

Every PR that changes observable behaviour MUST update the relevant documentation in the same
branch. Check each item that applies (run `/doc-check` to audit this against your diff):

- **New/removed/changed API endpoint?** → Update the OpenAPI/API spec (annotations or spec file).
  The generated API doc is the single source of truth for endpoints — do NOT list endpoints in the
  README.
- **Setup/commands/ports/services changed?** → Update the root `README.md` and infra/compose docs.
- **Frontend stack or structure changed?** → Update the frontend `README.md` / `CLAUDE.md`.
- **New environment variable?** → Add it to the env-vars reference (e.g. `docs/env-vars*.md`).
- **Deployment/infra changed?** → Update the deployment docs.
- **New sub-project/package?** → Add it to the project table in the root `README.md` / `CLAUDE.md`.
- **Security headers / CSP changed?** → Update the relevant headers config.
