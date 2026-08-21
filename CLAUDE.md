# Setlistify — Workspace

## Overview
Setlistify turns concerts you attend into streaming playlists, built from what the bands actually
played. You track a concert (bands, date, venue, ticket price), Setlistify pulls the band's real
setlists from setlist.fm, and generates a matching playlist in your streaming service. After the
show, the concert page is where you replay it and write down what it was like.

## Structure

| Directory | Component | Stack |
|-----------|-----------|-------|
| `backend/` | API, integrations, backoffice | PHP 8.4 · Symfony 8.1 · API Platform 4.3 · PostgreSQL · Redis |
| `frontend/` | Cross-platform client (web + iOS + Android) | Expo · React Native + react-native-web · Expo Router · TypeScript |
| `docker/` | Container definitions and compose overrides | Docker |
| `docs/` | Specs, prompts, architecture and operational reference | Markdown |

## Development

```bash
docker compose up -d          # postgres, redis, backend
cd frontend && npx expo start # --web | --ios | --android
```

| Service | URL |
|---------|-----|
| Frontend (web) | <http://localhost:8081> |
| Backend API | <http://localhost:8000/api/*> |
| OpenAPI spec / docs | <http://localhost:8000/api/docs> |
| Backoffice | <http://localhost:8000/admin> |

Everything runs in the project's own containers. See `docs/env-vars.md` before configuring anything
that touches an external API.

## Key Conventions
- All code (variables, functions, comments, DB fields) MUST be in English.
- Data persistence and sensitive logic live in the backend, never in the client.
- Security is an MVP requirement, not a stretch goal.

### Setlistify-specific rules

- **The streaming port is the only way to reach a provider.** All provider work goes through
  `StreamingProviderInterface`. No `Spotify`, `YouTube` or `Apple` symbol may appear outside its own
  adapter directory under `backend/src/Service/Streaming/`. Provider quirks (Spotify's `market`,
  YouTube's quota accounting, Apple's storefronts) stay behind the interface.
- **setlist.fm responses are always cached.** The standard API key allows 2 requests/second and
  **1,440 requests/day total** — for the whole application, not per user. Never call setlist.fm
  without going through the caching client. A cache miss is a budget decision.
- **Provider credentials never leave the secrets layer.** Client IDs and secrets come from
  environment/secret storage only. They are never committed, never logged, and never rendered in the
  backoffice — not even masked.
- **The backoffice edits behaviour, never credentials.** `ProviderSetting` holds flags (`enabled`,
  `playbackMode`, `isDefault`). If a value is a secret, it does not belong in the database.
- **Provider state is read at runtime, not baked in.** Anything that offers a provider to a user, or
  decides how a playlist is played back, reads `ProviderRegistry` — so a provider can be disabled
  mid-incident (e.g. YouTube's daily quota is exhausted) without a deploy.
- **Playlist generation degrades, it does not fail.** Missing setlists, unmatched songs and
  ambiguous versions are the normal case, not the error case. Always produce the best available
  result plus an honest report of what could not be matched.

### Domain glossary

| Term | Meaning |
|------|---------|
| **Concert** | An event the user tracks: one or more bands, a date, optionally a venue and ticket price. Upcoming or past. |
| **Setlist** | The ordered list of songs a band played at *one specific show*, as recorded on setlist.fm. Not ours; cached from them. |
| **Song** | An entry in a setlist. A name and a band — not yet a playable track. |
| **Track** | A concrete, playable item in a streaming provider's catalog. Matching turns Songs into Tracks. |
| **Playlist** | The generated result: a provider-side playlist linked to one Concert. |
| **Provider** | A streaming service behind the port (Spotify, YouTube, …). |
| **Fast mode** | Playlist generation with no user input: latest setlist, best-guess track matching. |
| **Normal mode** | Interactive generation: the user picks the setlist, then picks song versions. |

## Tooling

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

### RTK — token-optimised CLI

`rtk` proxies common read-only commands and strips the noise from their output (60-90% fewer
tokens). A `PreToolUse` hook rewrites bare commands automatically, so most of the time this happens
without you doing anything.

- Prefer `rtk <cmd>` for inspection: `rtk git status`, `rtk grep …`, `rtk ls`, `rtk find …`,
  `rtk docker …`.
- Use `rtk proxy <cmd>` to bypass the filtering when you genuinely need raw output to debug.
- `rtk gain` reports the tokens saved so far.

## API Contract

The backend and the Expo client grow together, so the API contract is the coupling point — it is a
single source of truth, and a change on one side must surface on the other at build time.

- **The OpenAPI spec is the single source of truth for endpoints.** API Platform generates it from
  the resource classes. `backend-engineer` updates the resource/annotations in the SAME change as any
  endpoint change (request/response shapes, status codes, auth, error format). Do NOT list endpoints
  in any README.
- **Generate the client, don't hand-write it.** `frontend/api/` is generated from the spec with
  `openapi-typescript`. `frontend-engineer` consumes the generated types — never redeclare request or
  response shapes by hand, so a breaking API change becomes a compile error in the client.
- **One feature, one spec, one branch.** A change spanning the API and the client is specified once
  (`docs/specs/…`), implemented on a single `feature/<name>` branch, and reviewed as one PR — never
  merged half-and-half across sides.
- **Regenerate before wiring up.** After the spec changes, regenerate the client types before editing
  client code that calls the new/changed endpoint.
- **The backoffice is not part of the contract.** EasyAdmin routes under `/admin` are server-rendered
  and must never appear in the public OpenAPI spec or be consumed by the Expo client.
- **Verify against the real integration.** Bring both sides up together (`docker compose up`) so
  `/test` and manual checks exercise the actual client↔API path, not mocks alone.

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
3. **Create feature branch**: `feature/<short-name>` from `master`.
4. **Implement**: Use the appropriate agent(s). Writing the tests that cover the change is part of
   this step, done by the same agent — not a separate hand-off.
5. **Commit on the feature branch**: Never directly on `master`.

The ordered backlog lives in `docs/prompts/`. Each file there is a ready-to-run prompt; start with
the lowest-numbered one that is not yet done.

### Bug Fix Workflow
When the user reports a bug:

1. **Diagnose**: Understand the bug.
2. **Create bugfix branch**: `bugfix/<short-name>` from `master`.
3. **Fix and test**.
4. **Commit on the bugfix branch**.

### Branch Rules
- NEVER commit directly to `master`.
- Branch naming: `feature/<name>` or `bugfix/<name>`, lowercase, hyphen-separated.
- If already on a feature/bugfix branch, continue on it.

> Note: the `check-branch.sh` hook enforces this by blocking source edits on the main branch.

### Documentation Update (mandatory before committing)

Every PR that changes observable behaviour MUST update the relevant documentation in the same
branch. Check each item that applies (run `/doc-check` to audit this against your diff):

- **New/removed/changed API endpoint?** → Update the API Platform resource so the OpenAPI spec
  regenerates. The generated spec is the single source of truth — do NOT list endpoints in the README.
- **Setup/commands/ports/services changed?** → Update the root `README.md` and this file.
- **Frontend stack or structure changed?** → Update the frontend `README.md`.
- **New environment variable?** → Add it to `docs/env-vars.md` AND to `.env.example`.
- **New or changed backoffice setting?** → Update `docs/architecture.md` and, if it affects a
  provider's legal classification, `docs/external-apis.md`.
- **External API behaviour, quota or terms changed?** → Update `docs/external-apis.md`.
- **Deployment/infra changed?** → Update the deployment docs.
- **New sub-project/package?** → Add it to the Structure table above and the root `README.md`.
- **Security headers / CSP changed?** → Update the relevant headers config.
