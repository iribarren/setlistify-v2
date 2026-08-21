/**
 * The refresh-token storage adapter's contract. D-18's one sanctioned platform branch: implemented
 * by `storage.native.ts` (`expo-secure-store`) and `storage.web.ts` (a no-op — the refresh token
 * lives only in the httpOnly cookie on web and this adapter must never touch a JS-readable store,
 * AC-3.5). Consumers import the bare `./storage` specifier; Metro/Jest resolve the platform file.
 */
export interface RefreshTokenStorage {
  getRefreshToken(): Promise<string | null>;
  setRefreshToken(token: string): Promise<void>;
  clearRefreshToken(): Promise<void>;
}
