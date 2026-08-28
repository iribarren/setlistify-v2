import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";

import * as authApi from "./api";
import { isNativePlatform } from "./platform";
import { performRefresh } from "./refreshCoordinator";
import { onSessionExpired } from "./sessionEvents";
import { refreshTokenStorage } from "./storage";
import { clearAccessToken, setAccessToken } from "./tokenStore";

export type SessionStatus = "restoring" | "authenticated" | "unauthenticated";

export interface SessionUser {
  id: number;
  email: string;
  emailVerified: boolean;
  roles: string[];
  /** D-269, AC-10.1 — gates the instant setlist refresh action on the playlist result screen. */
  canRefreshSetlistNow: boolean;
}

export interface SessionContextValue {
  /** AC-3.1: `"restoring"` until cold-start restore has settled one way or the other. */
  status: SessionStatus;
  user: SessionUser | null;
  register(email: string, password: string): Promise<void>;
  login(email: string, password: string): Promise<void>;
  logout(): Promise<void>;
  requestPasswordReset(email: string): Promise<void>;
  confirmPasswordReset(token: string, password: string): Promise<void>;
  confirmEmailVerification(token: string): Promise<void>;
  resendEmailVerification(): Promise<void>;
  /** Re-fetches `/api/me` — used after email verification to drop the unverified banner. */
  refreshUser(): Promise<void>;
}

const SessionContext = createContext<SessionContextValue | null>(null);

/**
 * AC-8.4: the ONE React context that owns session state. `useSession()` is the only sanctioned way
 * a screen learns who is logged in or performs an auth action — nothing outside `lib/auth/` reads
 * or writes a token.
 */
export function SessionProvider({ children }: { children: React.ReactNode }): React.JSX.Element {
  const [status, setStatus] = useState<SessionStatus>("restoring");
  const [user, setUser] = useState<SessionUser | null>(null);

  const clearLocalSession = useCallback(async () => {
    clearAccessToken();
    if (isNativePlatform()) {
      await refreshTokenStorage.clearRefreshToken();
    }
    setUser(null);
  }, []);

  const loadUser = useCallback(async () => {
    const me = await authApi.me();
    setUser({
      id: me.id,
      email: me.email,
      emailVerified: me.emailVerified,
      roles: me.roles,
      canRefreshSetlistNow: me.canRefreshSetlistNow,
    });
  }, []);

  // AC-3.1–AC-3.3: attempt restore before anything authenticated renders. Native reads the stored
  // refresh token; web attempts refresh via the httpOnly cookie with no token to read first.
  useEffect(() => {
    let cancelled = false;

    async function restore(): Promise<void> {
      try {
        await performRefresh();
        await loadUser();
        if (!cancelled) {
          setStatus("authenticated");
        }
      } catch {
        await clearLocalSession();
        if (!cancelled) {
          setStatus("unauthenticated");
        }
      }
    }

    void restore();
    return () => {
      cancelled = true;
    };
    // Runs once, on mount (cold start) — deliberately not re-run on `loadUser`/`clearLocalSession`
    // identity (both are stable via useCallback with an empty dependency array).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // AC-4.7: a background refresh that ultimately fails (a replayed/expired refresh token) routes
  // to login exactly once, driven by `status` flipping rather than an imperative navigation call.
  useEffect(() => {
    return onSessionExpired(() => {
      setUser(null);
      setStatus((current) => (current === "authenticated" ? "unauthenticated" : current));
    });
  }, []);

  const register = useCallback(async (email: string, password: string) => {
    await authApi.register(email, password);
  }, []);

  const login = useCallback(
    async (email: string, password: string) => {
      const tokens = await authApi.login(email, password);
      setAccessToken(tokens.accessToken);
      if (isNativePlatform() && tokens.refreshToken) {
        await refreshTokenStorage.setRefreshToken(tokens.refreshToken);
      }
      try {
        await loadUser();
        setStatus("authenticated");
      } catch (error) {
        await clearLocalSession();
        setStatus("unauthenticated");
        throw error;
      }
    },
    [clearLocalSession, loadUser],
  );

  const logout = useCallback(async () => {
    // AC-5.4: logout must never fail visibly — the backend call is best-effort.
    try {
      const presentedToken = isNativePlatform() ? await refreshTokenStorage.getRefreshToken() : null;
      await authApi.logout(presentedToken);
    } catch {
      // Swallowed deliberately — see above.
    } finally {
      await clearLocalSession();
      setStatus("unauthenticated");
    }
  }, [clearLocalSession]);

  const requestPasswordReset = useCallback(async (email: string) => {
    await authApi.requestPasswordReset(email);
  }, []);

  const confirmPasswordReset = useCallback(async (token: string, password: string) => {
    await authApi.confirmPasswordReset(token, password);
  }, []);

  const confirmEmailVerification = useCallback(async (token: string) => {
    await authApi.confirmEmailVerification(token);
  }, []);

  const resendEmailVerification = useCallback(async () => {
    await authApi.resendEmailVerification();
  }, []);

  const refreshUser = useCallback(async () => {
    await loadUser();
  }, [loadUser]);

  const value = useMemo<SessionContextValue>(
    () => ({
      status,
      user,
      register,
      login,
      logout,
      requestPasswordReset,
      confirmPasswordReset,
      confirmEmailVerification,
      resendEmailVerification,
      refreshUser,
    }),
    [
      status,
      user,
      register,
      login,
      logout,
      requestPasswordReset,
      confirmPasswordReset,
      confirmEmailVerification,
      resendEmailVerification,
      refreshUser,
    ],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

/** AC-8.4: the only sanctioned way a screen reads session state or performs an auth action. */
export function useSession(): SessionContextValue {
  const context = useContext(SessionContext);
  if (!context) {
    throw new Error("useSession() must be called within a SessionProvider.");
  }
  return context;
}
