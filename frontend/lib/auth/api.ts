import type { components } from "@/api";
import { apiClient, unwrap } from "@/lib/api";

// AC-8.6/CLAUDE.md: no hand-written request/response type — every shape below is derived from the
// generated schema (`frontend/api/`).
export type RegisteredUser = components["schemas"]["User.jsonld"];
export type Session = components["schemas"]["Login.jsonld"];
export type Me = components["schemas"]["Me.jsonld"];

export interface RequiredMe {
  id: number;
  email: string;
  emailVerified: boolean;
  roles: string[];
  createdAt: string;
}

function requireMe(body: Me): RequiredMe {
  // The generated type marks every field optional (Hydra/JSON-LD's schema shape), but
  // `MeStateProvider` always returns all four (AC-8.1) — narrow once, here, rather than at every
  // call site.
  return {
    id: body.id ?? 0,
    email: body.email ?? "",
    emailVerified: body.emailVerified ?? false,
    roles: (body.roles ?? []).filter((role): role is string => typeof role === "string"),
    createdAt: body.createdAt ?? new Date(0).toISOString(),
  };
}

/** `POST /api/users` (US-1, AC-1.1). */
export async function register(email: string, password: string): Promise<RegisteredUser> {
  return unwrap((signal) => apiClient.POST("/api/users", { body: { email, password }, signal }));
}

export interface SessionTokens {
  accessToken: string;
  expiresIn: number;
  /** Only present for `X-Client-Platform: native` requests (AC-4.6, D-18). */
  refreshToken: string | null;
}

function toSessionTokens(body: Session): SessionTokens {
  return {
    accessToken: body.accessToken ?? "",
    expiresIn: body.expiresIn ?? 0,
    refreshToken: body.refreshToken ?? null,
  };
}

/** `POST /api/login` (US-2). */
export async function login(email: string, password: string): Promise<SessionTokens> {
  const body = await unwrap((signal) => apiClient.POST("/api/login", { body: { email, password }, signal }));
  return toSessionTokens(body);
}

/**
 * `POST /api/token/refresh` (US-4). `refreshToken` is the native-stored plaintext; on web it is
 * omitted and the httpOnly cookie (sent via `credentials: "include"`, set globally on `apiClient`)
 * carries it instead (AC-4.6).
 */
export async function refresh(refreshToken: string | null): Promise<SessionTokens> {
  const body = await unwrap((signal) =>
    apiClient.POST("/api/token/refresh", { body: { refreshToken }, signal }),
  );
  return toSessionTokens(body);
}

/** `POST /api/logout` (US-5). Same transport split as refresh: body token on native, cookie on web. */
export async function logout(refreshToken: string | null): Promise<void> {
  await unwrap((signal) => apiClient.POST("/api/logout", { body: { refreshToken }, signal }));
}

/** `GET /api/me` (US-8, AC-8.1). */
export async function me(): Promise<RequiredMe> {
  const body = await unwrap((signal) => apiClient.GET("/api/me", { signal }));
  return requireMe(body);
}

/** `POST /api/password-reset/request` (US-6, AC-6.1) — always resolves; never signals whether the address exists. */
export async function requestPasswordReset(email: string): Promise<void> {
  await unwrap((signal) => apiClient.POST("/api/password-reset/request", { body: { email }, signal }));
}

/** `POST /api/password-reset/confirm` (US-6, AC-6.3). */
export async function confirmPasswordReset(token: string, password: string): Promise<void> {
  await unwrap((signal) =>
    apiClient.POST("/api/password-reset/confirm", { body: { token, password }, signal }),
  );
}

/** `POST /api/email-verification/confirm` (US-7, AC-7.2). */
export async function confirmEmailVerification(token: string): Promise<void> {
  await unwrap((signal) =>
    apiClient.POST("/api/email-verification/confirm", { body: { token }, signal }),
  );
}

/** `POST /api/email-verification/resend` (US-7, AC-7.3) — requires an authenticated session. */
export async function resendEmailVerification(): Promise<void> {
  await unwrap((signal) => apiClient.POST("/api/email-verification/resend", { signal }));
}
