Write or expand tests for: $ARGUMENTS

Use this after implementing or changing behaviour, to satisfy the test-coverage reminder emitted
by the `check-tests` Stop hook.

1. Identify what changed. If `$ARGUMENTS` is empty, inspect the current diff
   (`git diff` and `git diff --staged`) to determine which code needs coverage.
2. Write or update tests that cover the new/changed behaviour — happy path, edge cases, and error
   handling. If the change belongs to a specific stack, use the matching coding agent
   (`backend-engineer`, `frontend-engineer`, `mobile-engineer`, `game-ui-engineer`) to write them, in
   line with that stack's conventions; otherwise write them directly.
3. Run the test suite and report the results. If tests fail, report the failure output; do not
   claim success unless the suite is green.

Start by determining the scope of the change now.
