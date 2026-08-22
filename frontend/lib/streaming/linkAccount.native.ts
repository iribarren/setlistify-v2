import * as WebBrowser from "expo-web-browser";

import type { LinkAccount } from "./linkAccountTypes";

/**
 * D-74/D-75/AC-1.8, native half of the platform fork (`linkAccount.web.ts` mirrors it). Runs the
 * OAuth round trip in an in-app auth session (`WebBrowser.openAuthSessionAsync`) rather than the
 * system browser, so the return leg is one continuous flow the OS treats as a single interaction.
 *
 * The return URL matches `STREAMING_LINK_RETURN_URL_NATIVE` (`backend/.env.example`) — the
 * `setlistify://` scheme is already registered (`app.json`). It must stay in sync with that env var
 * if a future change moves where the backend redirects native clients back to.
 */
const NATIVE_RETURN_URL = "setlistify://account";

export const linkAccount: LinkAccount = async (authorizationUrl) => {
  const result = await WebBrowser.openAuthSessionAsync(authorizationUrl, NATIVE_RETURN_URL);

  if (result.type !== "success" || !("url" in result)) {
    // AC-1.10: dismissed/cancelled — the account state is unchanged and there is nothing to resolve.
    return { ref: null, cancelled: true };
  }

  const ref = extractRef(result.url);
  return { ref, cancelled: ref === null };
};

/** Exported for a direct unit test of the URL parsing, without stubbing `expo-web-browser`. */
export function extractRef(url: string): string | null {
  try {
    return new URL(url).searchParams.get("ref");
  } catch {
    return null;
  }
}
