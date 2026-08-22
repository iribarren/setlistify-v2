export type { LinkAccount, LinkAccountResult } from "./linkAccountTypes";

export {
  useStreamingAccounts,
  useStartStreamingLink,
  useResolveStreamingLink,
  useUnlinkStreamingAccount,
  streamingAccountsQueryKey,
  type StreamingAccountOutput,
  type StreamingAccountStatus,
  type StreamingLinkResult,
} from "./queries";

export {
  describeStreamingError,
  providerDisplayName,
  revocationFollowUp,
  type RevocationFollowUp,
} from "./errorMessage";

/** The one provider this branch offers to connect (D-86: availability is assumed, prompt 11 owns it). */
export const SUPPORTED_PROVIDERS = ["spotify"] as const;
export type SupportedProvider = (typeof SUPPORTED_PROVIDERS)[number];
