#!/usr/bin/env bash
# Fails if a variable documented in docs/env-vars.md is missing from the corresponding
# .env.example (AC-4.5). Intentionally simple: a name-extraction diff, not a parser.
set -euo pipefail
export LC_ALL=C

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

ENV_VARS_DOC="docs/env-vars.md"
BACKEND_EXAMPLE="backend/.env.example"
FRONTEND_EXAMPLE="frontend/.env.example"

# Names documented in docs/env-vars.md: any ALL_CAPS_WITH_UNDERSCORES token wrapped in
# backticks inside the "## Variables" section onward.
documented=$(
  sed -n '/^## Variables/,$p' "$ENV_VARS_DOC" \
    | grep -oE '`[A-Z][A-Z0-9_]*`' \
    | tr -d '`' \
    | grep -vE '_$' \
    | sort -u
)

# Names actually present in both .env.example files (left-hand side of NAME=..., ignore comments).
provided=$(
  grep -hE '^[A-Z][A-Z0-9_]*=' "$BACKEND_EXAMPLE" "$FRONTEND_EXAMPLE" \
    | sed -E 's/=.*$//' \
    | sort -u
)

missing=$(comm -23 <(echo "$documented") <(echo "$provided"))

if [ -n "$missing" ]; then
  echo "The following variables are documented in $ENV_VARS_DOC but missing from" >&2
  echo "$BACKEND_EXAMPLE / $FRONTEND_EXAMPLE:" >&2
  echo "$missing" >&2
  exit 1
fi

echo "OK: every variable documented in $ENV_VARS_DOC is present in .env.example."
