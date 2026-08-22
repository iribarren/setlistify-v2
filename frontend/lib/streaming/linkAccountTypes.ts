/**
 * D-74/D-75, prompt 10 spec §"Frontend shape": the single shared contract for the app's other
 * sanctioned platform fork (`linkAccount.web.ts` / `linkAccount.native.ts`), same shape as
 * `components/DateFieldTypes.ts` (D-34). No screen imports `Platform` or `expo-web-browser`
 * directly — a screen imports `@/lib/streaming/linkAccount` and Metro/Jest/`tsc` resolve the right
 * file for the platform it's building (via `tsconfig.json`'s `moduleSuffixes`).
 */
export interface LinkAccountResult {
  /**
   * The opaque, one-time link-result reference from `STREAMING_LINK_RETURN_URL_*` (AC-1.7/AC-1.8).
   * `null` when the round trip did not produce one — either the user cancelled (`cancelled: true`)
   * or, on web, the function's promise never really settles at all (see `linkAccount.web.ts`).
   */
  ref: string | null;
  /** `true` when the user closed/dismissed the flow before the provider redirected back (AC-1.10). */
  cancelled: boolean;
}

/**
 * Opens `authorizationUrl` (produced by `POST /api/streaming/link`, never assembled client-side —
 * AC-1.1) and resolves once the round trip returns to Setlistify. The client never sees a code, a
 * PKCE verifier or a token at any point in this function (D-74) — only the URL to open and,
 * afterwards, the opaque reference to resolve via `useResolveStreamingLink`.
 */
export type LinkAccount = (authorizationUrl: string) => Promise<LinkAccountResult>;
