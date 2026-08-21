import { refresh as refreshRequest, type SessionTokens } from "./api";
import { emitSessionExpired } from "./sessionEvents";
import { isNativePlatform } from "./platform";
import { setAccessToken } from "./tokenStore";
import { refreshTokenStorage } from "./storage";

/**
 * AC-4.5: N concurrent callers (N racing 401s, or a 401 racing `SessionProvider`'s own
 * cold-start restore) must trigger exactly ONE `/api/token/refresh` call, with every caller
 * awaiting the same in-flight promise rather than issuing their own. A module-level variable is
 * enough for this — there is exactly one refresh in flight at a time, app-wide, by construction.
 */
let inFlight: Promise<SessionTokens> | null = null;

/**
 * Performs (or joins) the single refresh in flight. On success, updates the in-memory access token
 * and — on native only — the persisted refresh token, then returns the new tokens. On failure,
 * clears the in-memory access token and native storage, emits `sessionExpired` (AC-4.7: the caller
 * — `SessionProvider` — reacts by routing to login exactly once, not this module), and rethrows.
 */
export async function performRefresh(): Promise<SessionTokens> {
  if (inFlight) {
    return inFlight;
  }

  inFlight = doRefresh().finally(() => {
    inFlight = null;
  });

  return inFlight;
}

async function doRefresh(): Promise<SessionTokens> {
  const native = isNativePlatform();
  const presentedToken = native ? await refreshTokenStorage.getRefreshToken() : null;

  if (native && !presentedToken) {
    // Nothing to present — fail fast without a network round trip (AC-4.8 still holds: this never
    // reaches the server, so it can't leak "unknown vs. expired" either).
    await clearSession();
    emitSessionExpired();
    throw new Error("No refresh token available.");
  }

  try {
    const tokens = await refreshRequest(presentedToken);
    setAccessToken(tokens.accessToken);
    if (native && tokens.refreshToken) {
      await refreshTokenStorage.setRefreshToken(tokens.refreshToken);
    }
    return tokens;
  } catch (error) {
    await clearSession();
    emitSessionExpired();
    throw error;
  }
}

async function clearSession(): Promise<void> {
  setAccessToken(null);
  if (isNativePlatform()) {
    await refreshTokenStorage.clearRefreshToken();
  }
}
