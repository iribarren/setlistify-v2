---
name: monorepo-infra-decisions
description: Non-obvious infra choices and gotchas from implementing docs/specs/2026-08-21-monorepo-and-environments.md (compose stack, FrankenPHP, gitleaks)
type: project
---

Built the greenfield infra layer (backend/, frontend/, docker/, compose.yaml, CI, gitleaks
pre-commit hook) for [[setlistify]] on branch `feature/monorepo-and-environments`.

**FrankenPHP listens on :8080 inside the container, not :80.** Chose this deliberately so the
non-root `appuser` (UID/GID from build args, default 1000) never needs `CAP_NET_BIND_SERVICE` —
binding <1024 as non-root fails otherwise. Host side still maps the documented port 8000
(`8000:8080` in compose.yaml). Set via `ENV SERVER_NAME=:8080` in `docker/backend/Dockerfile`.
**Why:** AC-5.2 in the spec required non-root `USER` before CMD; ports <1024 as non-root is the
standard blocker.
**How to apply:** if a future change needs the container to listen on a privileged port, either add
`setcap` for `cap_net_bind_service` on the caddy binary in the Dockerfile, or keep it non-privileged
and rely on the compose port mapping (current approach) — don't just change SERVER_NAME to `:80`
without one of those.

**Bind-mount UID/GID must match the host user or writes get Permission denied.** `compose.yaml`
reads `APP_UID`/`APP_GID` from the shell env with a default of 1000. The default only works if the
developer's host UID/GID happen to be 1000 (common on a fresh Ubuntu install's first user, but this
dev machine's user is 1001). README documents `export APP_UID=$(id -u) APP_GID=$(id -g))` before
`docker compose build`/`up`. **Why:** AC-5.3 (bind-mounted writes owned by the developer, no host
chown workaround). **How to apply:** if `docker compose exec backend touch /app/x` ever fails with
Permission denied again, the build args didn't match the host UID — rebuild with the export above.

**gitleaks' default ruleset allowlists well-known example secrets.** AWS's own documented example
key (`AKIAIOSFODNN7EXAMPLE...`) does NOT trigger gitleaks — its default rules filter strings
containing "EXAMPLE". When testing the pre-commit hook or CI secret-scan, use a genuinely
random-looking fake secret (e.g. `sk_live_<random 24 chars>`), not a textbook example value, or
you'll get a false "it doesn't work" result. **Why:** wasted a debugging cycle on this during
implementation before finding the real test worked. **How to apply:** whenever asked to verify a
secret scanner actually blocks something, generate a random credential-shaped string rather than
copying a well-known placeholder from docs/tutorials.

**No git remote is configured on this local clone** (`git remote -v` empty), though `gh auth status`
shows the user logged in as `iribarren`. Could not verify AC-6.4 (pipeline green on a real GitHub
Actions run) or confirm "repository hosted on GitHub with Actions enabled" — both flagged as **open
dependencies** in the spec itself, to confirm during implementation rather than assume. All CI logic
was instead validated by running the equivalent steps locally (docker compose build/up/healthy,
gitleaks scan, drift-check script). **Why:** spec's Dependencies section explicitly lists these as
unconfirmed. **How to apply:** before claiming CI is "green," check whether a remote/PR now exists;
if not, the claim can only be "the workflow's steps were verified to work locally."

**gitleaks image `zricethezav/gitleaks:v8.30.1`** is used identically in `.githooks/pre-commit` and
`.github/workflows/ci.yml` (`git --config /repo/.gitleaks.toml --redact`, add `--staged` for the
pre-commit-only staged-diff variant) — deliberately kept in sync so a CI failure reproduces locally
with one command.

Compose spec version in this environment is Docker Compose v2.21.0 (older) — the `env_file:` long
object form (`path:`/`required:`) is NOT supported; must use the plain string list form
(`env_file: [./backend/.env.local]`). Hit a validation error over this during implementation.
