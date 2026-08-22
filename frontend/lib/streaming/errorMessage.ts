import { ApiError } from "@/lib/api";

/** Mirrors `lib/concerts/errorMessage.ts`'s shape: an honest, non-generic message per status. */
export function describeStreamingError(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 0) {
      return "Couldn't reach the server. Check your connection and try again.";
    }
    if (error.status === 403) {
      return "Please log in again to continue.";
    }
    if (error.status === 404) {
      return "That connection could not be found — it may have already been removed.";
    }
    return error.detail ?? error.title;
  }
  return "Something went wrong. Please try again.";
}

/** Only `"spotify"` exists today (this branch's one adapter) — extend as providers are added. */
export function providerDisplayName(provider: string): string {
  if (provider === "spotify") {
    return "Spotify";
  }
  return provider.length > 0 ? provider.charAt(0).toUpperCase() + provider.slice(1) : provider;
}

/**
 * D-81/AC-3.3: Spotify exposes no token revocation endpoint — deleting `StreamingAccount` removes
 * Setlistify's own copy, but the user must also remove the app from their Spotify account to revoke
 * access there. This is UI copy, decided honestly rather than left implicit (D-81's whole point);
 * the backend response carries no field for it, since revocation support is a property of the
 * adapter, not of any one account. Extend the map if a future provider has the same gap.
 */
const PROVIDERS_WITHOUT_REVOCATION: Record<string, string> = {
  spotify: "https://www.spotify.com/account/apps/",
};

export interface RevocationFollowUp {
  message: string;
  url: string;
}

export function revocationFollowUp(provider: string): RevocationFollowUp | null {
  const url = PROVIDERS_WITHOUT_REVOCATION[provider];
  if (!url) {
    return null;
  }
  const name = providerDisplayName(provider);
  return {
    message: `Setlistify has deleted its copy of your ${name} connection. To fully remove its access, also remove Setlistify from your ${name} account settings.`,
    url,
  };
}
