import type { RefreshTokenStorage } from "./storageTypes";

/**
 * AC-3.5/D-18: on web the refresh token is never held anywhere the client can read — it lives
 * solely in the httpOnly, `Secure`, `SameSite=Strict` cookie the backend sets on `/api` responses
 * (`RefreshCookieFactory`). This adapter is deliberately inert: no `localStorage`,
 * `sessionStorage` or IndexedDB write, ever. `getRefreshToken` always resolves `null` — restore and
 * refresh on web go through the cookie (`credentials: "include"`), never a value read from here.
 */
export const refreshTokenStorage: RefreshTokenStorage = {
  async getRefreshToken(): Promise<string | null> {
    return null;
  },
  async setRefreshToken(): Promise<void> {
    // Intentionally a no-op — see file header.
  },
  async clearRefreshToken(): Promise<void> {
    // Intentionally a no-op — see file header.
  },
};
