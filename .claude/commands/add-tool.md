Add an optional external tool (RTK, CodeGraph, SpecKit, …) to this already-bootstrapped project.

Argument: `$ARGUMENTS` — optionally the tool id to add (e.g. `codegraph`). If omitted, show the
available tools and ask which one.

The tool registry lives in the `claude-bootstrap` repo under `tools/`. This project records where
that repo is in `.claude/.bootstrap-manifest.json` (`bootstrapSource`), along with the tools already
selected.

Steps:

1. Read `.claude/.bootstrap-manifest.json` to get `bootstrapSource` and the current `tools` list. If
   the file is missing, the project was set up before the registry existed — ask the user for the
   path to their `claude-bootstrap` checkout.
2. Run `bash <bootstrapSource>/setup-claude.sh --list-tools` to show what's available and whether
   each prerequisite is installed on this machine.
3. If `$ARGUMENTS` named a tool, use it. Otherwise present the list (marking the ones already
   installed in this project) and ask the user to choose.
4. Run the installer for the chosen tool, keeping the project's existing name and type from the
   manifest:
   `bash <bootstrapSource>/setup-claude.sh . --name "<name>" --type <type> --tools <id>`
   Re-running is additive — previously selected tools are preserved.
5. Report what changed: permissions added, whether a `CLAUDE.md` **Tooling** section was written,
   any commands that were superseded, and any post-install step the installer printed (for example a
   missing prerequisite to install, or a hook to wire).
6. Remind the user to restart Claude Code so new permissions, commands and MCP servers take effect.

Note: the installer never downloads or installs a prerequisite itself. If one is missing it prints
the exact command — relay it, don't run it unless the user asks.

Start by reading the manifest now.
