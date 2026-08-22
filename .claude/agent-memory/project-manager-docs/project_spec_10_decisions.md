---
name: spec-10-streaming-port-decisions
description: Decisions D-71..D-88 proposed by the 2026-08-22 streaming-port-and-account-linking spec — frozen 9-method port, tagged-service locator, backend-owned OAuth, key-id token encryption, needs_reauth status
metadata:
  type: project
---

`docs/specs/2026-08-22-streaming-port-and-account-linking.md` (backlog prompt 10) proposes **D-71
through D-88**. Status as written: **draft, awaiting user approval**.

- **D-71** — the port is frozen at `docs/architecture.md` §4's nine methods; a provider needing more
  gets a capability VO or nothing, never a tenth method.
- **D-72** — adapters resolved by `key()` through a tagged-service locator; no consumer names an
  adapter class. Unknown key = typed domain error.
- **D-73** — error mapping happens inside the adapter; no HTTP status or provider error escapes.
  `ProviderUnavailable` is the catch-all.
- **D-74** — backend owns the whole OAuth exchange; client never holds a code, verifier or token.
  PKCE used anyway despite being a confidential client.
- **D-75** — **one** registered redirect URI per environment (the backend); platform split (web
  route vs `setlistify://`) happens after the exchange, carrying a one-time opaque reference.
- **D-76** — `state` is a Redis record (single-use, TTL), not a signed blob. Redis down = linking
  down, deliberately.
- **D-77** — `StreamingAccount` copies `Concert`'s 404-not-403 shape exactly (own extension + voter);
  `ConcertOwnerExtension` untouched.
- **D-78** — encryption via custom Doctrine type, envelope `v<ver>:<keyId>:<b64(nonce‖ct)>`, active
  key + `TOKEN_ENCRYPTION_KEYS_RETIRED` decrypt-only set. Unknown key id fails loudly.
- **D-79** — refresh is proactive, centralised in `StreamingTokenManager`, single-flight per account
  via `symfony/lock`. Callers never call `refreshToken()`.
- **D-80** — unrecoverable grant failure → `needs_reauth` + tokens cleared, row kept; transient
  failures never change status.
- **D-81** — *honest finding*: **Spotify exposes no token revocation endpoint.** Unlink deletes
  locally (authoritative), revokes only where supported, and the UI tells the user the extra step.
- **D-82** — symbol ban enforced by a static test over `backend/src/` only; env-var names are not
  symbols, and all Spotify parameter binding is confined to one config file. Write the test FIRST.
- **D-83** — confidence score is explicitly provisional and isolated in one method; prompt 12 owns it.
- **D-84** — backoffice sees linked accounts and can unlink (audited); never sees or sets a token.
- **D-85** — recorded fixtures only; one `@group live` smoke test, never in CI (D-2) — CI would also
  burn one of the 5 capped user slots.
- **D-86** — a `ProviderAvailability` seam is consumed now with a constant implementation so prompt
  11's `ProviderSetting` replaces one class and changes no call site.
- **D-87** — playlists are private by default (`playlist-modify-private` only).
- **D-88** — scopes declared in one adapter constant, minimal; granted scopes stored as *returned*.

**Why:** Prompt 10 is the feature the whole roadmap's cost depends on — Spotify is permanently capped
at 5 users, so the product's viability is "provider #2 is one directory". Most decisions exist to make
that literally true and testable rather than aspirational.

**How to apply:** Highest D-number after this spec is **D-88** — continue from D-89. Nine new env
vars proposed: `TOKEN_ENCRYPTION_KEY_ID`, `TOKEN_ENCRYPTION_KEYS_RETIRED`, `SPOTIFY_API_BASE_URL`,
`SPOTIFY_ACCOUNTS_BASE_URL`, `STREAMING_HTTP_TIMEOUT`, `STREAMING_TOKEN_REFRESH_SKEW`,
`STREAMING_LINK_STATE_TTL`, `STREAMING_LINK_RETURN_URL_WEB`, `STREAMING_LINK_RETURN_URL_NATIVE`.
Four open questions left for the user (unlink copy tone, admin unlink, skew/TTL defaults, whether
`needs_reauth` gets a global banner). See [[backlog-prompt-to-spec-flow]],
[[spec-04-auth-decisions]] and [[spec-08-backoffice-decisions]].
