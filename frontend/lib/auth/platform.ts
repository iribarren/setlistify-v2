import { Platform } from "react-native";

/**
 * The value of `X-Client-Platform` (see `docs/specs/2026-08-21-auth-and-accounts.md`'s frontend
 * deviation note, and `backend/src/Service/Security/ClientPlatform.php`). `native` makes the
 * backend return the refresh token in the JSON body (stored in `expo-secure-store`); anything else
 * — including `web` — gets the httpOnly-cookie-only behaviour (D-18). Attached in exactly one
 * place: `lib/auth/authMiddleware.ts`'s request middleware.
 */
export function clientPlatformHeader(): "native" | "web" {
  return Platform.OS === "web" ? "web" : "native";
}

export function isNativePlatform(): boolean {
  return Platform.OS !== "web";
}
