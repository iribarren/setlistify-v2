import * as SecureStore from "expo-secure-store";

import type { RefreshTokenStorage } from "./storageTypes";

/**
 * AC-3.4/D-18: `expo-secure-store` (Keychain on iOS, encrypted `SharedPreferences` on Android) —
 * never `AsyncStorage`, never any unencrypted store. This file is a no-op on web by construction:
 * Metro/Jest resolve `storage.web.ts` there instead, so `expo-secure-store` (itself a no-op module
 * on web) is never even imported in a web bundle.
 */
const REFRESH_TOKEN_KEY = "setlistify.refreshToken";

export const refreshTokenStorage: RefreshTokenStorage = {
  async getRefreshToken(): Promise<string | null> {
    return SecureStore.getItemAsync(REFRESH_TOKEN_KEY);
  },
  async setRefreshToken(token: string): Promise<void> {
    await SecureStore.setItemAsync(REFRESH_TOKEN_KEY, token);
  },
  async clearRefreshToken(): Promise<void> {
    await SecureStore.deleteItemAsync(REFRESH_TOKEN_KEY);
  },
};
