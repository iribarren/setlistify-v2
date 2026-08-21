/**
 * AC-8.4 / D-18: the access token lives in memory ONLY, in this one module — never in
 * `AsyncStorage`, `localStorage` or any persisted store, on any platform. This is intentionally a
 * plain module-level variable rather than React state: `lib/auth/authMiddleware.ts` (a request
 * interceptor, outside React) must be able to read/write it synchronously without a render cycle,
 * and it must survive across `SessionProvider` remounts within the same JS runtime.
 *
 * Nothing outside `lib/auth/` imports this module directly (see `lib/auth/index.ts`'s barrel,
 * which does not re-export it) — `useSession()` is the only sanctioned way a screen learns whether
 * it is authenticated (AC-8.4).
 */
let accessToken: string | null = null;

export function getAccessToken(): string | null {
  return accessToken;
}

export function setAccessToken(token: string | null): void {
  accessToken = token;
}

export function clearAccessToken(): void {
  accessToken = null;
}
