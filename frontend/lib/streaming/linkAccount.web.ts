import type { LinkAccount, LinkAccountResult } from "./linkAccountTypes";

/**
 * D-74/D-75, web half of the platform fork (`linkAccount.native.ts` mirrors it). The browser does a
 * full-page redirect to the authorization URL the backend produced; nothing here awaits the round
 * trip, because navigating away unmounts whatever called this. The backend eventually redirects the
 * browser back to `STREAMING_LINK_RETURN_URL_WEB` (`/account?ref=…`, AC-1.7) — the account screen
 * picks the reference up from its own route params on the next mount and resolves it there
 * (`useResolveStreamingLink`), not through this function's return value.
 */
export const linkAccount: LinkAccount = (authorizationUrl) => {
  window.location.assign(authorizationUrl);
  // Intentionally never resolves/rejects — the page is about to navigate away. Typed to match the
  // native half so a caller can `await` either without a platform branch of its own.
  return new Promise<LinkAccountResult>(() => {});
};
