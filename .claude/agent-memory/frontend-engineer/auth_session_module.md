---
name: auth_session_module
description: How frontend/lib/auth/ is structured (session module, storage adapters, single-flight refresh) — read before touching auth or adding a new protected screen
metadata:
  type: project
---

Shipped in `feature/auth-and-accounts` (spec: `docs/specs/2026-08-21-auth-and-accounts.md`,
`docs/architecture.md` D-18–D-23). See [[frontend_stack]] for the base app conventions this builds
on.

**Module layout** (`frontend/lib/auth/`): `tokenStore.ts` (in-memory access token, module-level, no
React), `storageTypes.ts` + `storage.native.ts`/`storage.web.ts` (the one platform-branched file
pair, D-18 — native uses `expo-secure-store`, web is inert since the refresh token lives only in
the httpOnly cookie), `sessionEvents.ts` (a tiny pub/sub so the non-React middleware can tell
`SessionProvider` a refresh ultimately failed), `refreshCoordinator.ts` (single-flight
`performRefresh()`), `authMiddleware.ts` (openapi-fetch middleware: attaches
`Authorization`/`X-Client-Platform`, rewrites `Content-Type: application/json` →
`application/ld+json`, retries once on 401), `api.ts` (typed wrappers over the generated auth
endpoints), `SessionProvider.tsx`/`useSession()` (the one context screens use).

**Known gap that had to be fixed to make this work at all**: `backend/config/packages/
nelmio_cors.yaml` shipped without `allow_credentials: true` or `X-Client-Platform` in
`allow_headers`, even though D-18 explicitly requires both (credentialed cookie + the platform
header seam). Without it the web login→cookie→refresh cycle is silently broken cross-origin
(browser drops the cookie / preflight rejects the header) — confirmed via a raw `curl -i -X
OPTIONS`. Fixed as part of the frontend branch since nothing about the client can work without it;
worth checking this file first if a future auth-adjacent feature "works in Postman/curl but not the
browser".

**How to apply**: any new screen that needs auth reads `useSession()` — never `tokenStore`/
`storage.*` directly (only `lib/auth/`'s own files should import those). A new protected route goes
under `app/(app)/`; a new public-but-auth-adjacent route (like `verify-email`) goes at the app root,
outside both `(auth)`/`(app)` groups, if it must be reachable regardless of session state.
