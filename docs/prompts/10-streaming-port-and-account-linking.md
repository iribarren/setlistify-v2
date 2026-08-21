# 10 — Streaming port and account linking

**Command:** `/feature streaming-port-and-account-linking` · **Agent:** `backend-engineer` + `frontend-engineer` · **Depends on:** 04, 08

## Goal
The abstraction every streaming provider is reached through, plus its first implementation: a user
links their Spotify account via OAuth, and their tokens are stored encrypted and refreshed
automatically.

## Context
**Read `docs/architecture.md` §4 and `docs/external-apis.md` §Spotify first.**

This prompt creates the most important abstraction in the codebase. The reason it matters is
concrete: Spotify's Development Mode caps the app at **5 allowlisted users**, and Extended Quota Mode
requires 250k MAU — unreachable. Spotify is therefore a reference adapter for dogfooding, and the
product's viability depends on adding providers cheaply later. If provider-specific logic leaks
outside its adapter directory now, prompt 18 becomes a rewrite instead of a new directory.

**The rule, from `CLAUDE.md`:** no `Spotify` symbol may appear anywhere outside
`backend/src/Service/Streaming/Spotify/`.

## Scope

**The port**
- `StreamingProviderInterface` as sketched in `docs/architecture.md` §4: `key()`,
  `authorizationUrl()`, `exchangeCode()`, `refreshToken()`, `searchTrack()`, `createPlaylist()`,
  `addTracks()`, `playlistEmbedUrl()`, `playlistDeepLink()`.
- Shared value objects: `ProviderTokens`, `SongQuery`, `TrackCandidate` (provider track id, title,
  artist, album, duration, `isLive`, `isCover`, normalized confidence), `PlaylistDraft`,
  `ProviderPlaylist`.
- A locator resolving a provider key to its implementation via tagged services, so registering an
  adapter requires no change to consuming code.
- A provider-agnostic error taxonomy: `TokenExpired`, `RateLimited`, `QuotaExhausted`, `NotFound`,
  `RegionRestricted`, `ProviderUnavailable`. Callers handle these, never provider-specific errors.

**The Spotify adapter**
- OAuth 2.0 Authorization Code + PKCE, minimal scopes, `state` validated against CSRF.
- Token exchange and refresh; refresh handled transparently and proactively before expiry.
- `searchTrack()` returning ranked `TrackCandidate`s (the ranking itself is prompt 12's problem —
  return the raw candidates with a naive score for now).
- Playlist create and add-tracks.

**Storage**
- `StreamingAccount`: user, provider key, **encrypted** access and refresh tokens, scopes, expiry,
  provider account id and display name.
- Encryption at rest via a custom Doctrine type using libsodium `xchacha20poly1305`, with a key id
  stored alongside each ciphertext so `TOKEN_ENCRYPTION_KEY` can be rotated.

**API and client**
- Endpoints to start linking, handle the callback, list linked accounts, and unlink.
- Client screens: link/unlink, connection status, and a clear re-authorization path when a token is
  irrecoverably invalid.
- Deep-link/redirect handling that works on web, iOS and Android.

## Out of scope
- Which providers are active, and playback mode — prompt 11. Assume Spotify is enabled for now.
- Track matching quality — prompt 12.
- Playlist generation — prompt 14.
- YouTube — prompt 18.

## Acceptance criteria
- [ ] A user completes the Spotify OAuth flow on web, iOS and Android and sees their account linked.
- [ ] **Tokens are encrypted at rest** — verified by inspecting the raw column in a test.
- [ ] Expired access tokens refresh transparently without user-visible failure.
- [ ] Unlinking revokes and deletes stored tokens.
- [ ] **No `Spotify` symbol exists outside `Service/Streaming/Spotify/`** — enforced by an
      architecture test, not by convention.
- [ ] Provider errors surface as the shared taxonomy, never as Spotify-shaped errors.
- [ ] `state` is validated; a mismatched callback is rejected.
- [ ] Tokens never appear in a response, a log, or the backoffice.
- [ ] Key rotation is possible: a record encrypted with an old key id still decrypts.
- [ ] A second adapter could be added by creating one directory and one settings row — demonstrated by
      a test double implementing the interface with no changes to consuming code.

## Risks & open questions
- **The 5-user Development Mode cap will be hit immediately.** Allowlist your test accounts in the
  Spotify dashboard before starting, and expect confusing 403s from any account you forgot.
- Use two Spotify app registrations (dev + prod) with different redirect URIs, per `docs/env-vars.md`.
- OAuth redirects across web, iOS and Android are the fiddliest part of this prompt — budget time for
  the deep-link plumbing specifically.
- The architecture test enforcing adapter isolation is worth more than it looks. Write it early; it is
  what keeps prompt 18 cheap.
