import {
  useMutation,
  useQuery,
  useQueryClient,
  type QueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";

import type { components } from "@/api";
import { apiClient, ApiError, unwrap } from "@/lib/api";

export type StreamingAccountOutput =
  components["schemas"]["StreamingAccount.StreamingAccountOutput.jsonld"];

/** AC-2.2: the three statuses the backend derives — never computed client-side. */
export type StreamingAccountStatus = "connected" | "needs_reauth" | "revoked_by_user";

export interface StreamingLinkResult {
  provider: string;
  success: boolean;
  reason: string | null;
}

// AC-8.4 (frontend skeleton pattern): a stable domain-labelled query key tuple.
export const streamingAccountsQueryKey = ["streaming", "accounts"] as const;

/** US-2: the current user's linked accounts. Never token material — see the generated output type. */
export function useStreamingAccounts(): UseQueryResult<StreamingAccountOutput[], ApiError> {
  return useQuery({
    queryKey: streamingAccountsQueryKey,
    queryFn: async () => {
      const body = await unwrap(async (signal) =>
        apiClient.GET("/api/streaming/accounts", { signal }),
      );
      return body.member ?? [];
    },
  });
}

/**
 * US-1/US-5, AC-1.1: starts (or restarts, for `needs_reauth` — AC-5.3) the OAuth round trip for a
 * provider key and returns the authorization URL to open. The caller passes this straight to
 * `linkAccount()` (`@/lib/streaming/linkAccount`) — this hook never opens a URL itself.
 */
export function useStartStreamingLink(): UseMutationResult<string, ApiError, string> {
  return useMutation({
    mutationFn: async (provider: string) => {
      const body = await unwrap(async (signal) =>
        apiClient.POST("/api/streaming/link", { body: { provider }, signal }),
      );
      if (!body.authorizationUrl) {
        throw new Error("The server did not return an authorization URL.");
      }
      return body.authorizationUrl;
    },
  });
}

/**
 * AC-1.7/AC-1.8: resolves the one-time opaque reference returned after the OAuth callback redirect
 * into a success/failure outcome, then refreshes the accounts list regardless of the outcome — a
 * failed link still needs the (unchanged) list re-read so a stale "connecting…" state never lingers.
 */
export function useResolveStreamingLink(): UseMutationResult<
  StreamingLinkResult,
  ApiError,
  string
> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (ref: string) => {
      const body = await unwrap(async (signal) =>
        apiClient.GET("/api/streaming/link-results/{ref}", { params: { path: { ref } }, signal }),
      );
      return {
        provider: body.provider ?? "",
        success: body.success ?? false,
        reason: body.reason ?? null,
      };
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: streamingAccountsQueryKey });
    },
  });
}

interface UnlinkContext {
  previous?: StreamingAccountOutput[];
}

function removeById(
  accounts: StreamingAccountOutput[] | undefined,
  id: string,
): StreamingAccountOutput[] {
  return (accounts ?? []).filter((account) => String(account.id) !== id);
}

/**
 * US-3, AC-3.6: optimistic removal with reconciliation — the row disappears immediately, and a
 * failure restores the exact previous list (snapshot/rollback) rather than re-deriving it. Mirrors
 * `useCreateConcert`'s onMutate/onError/onSuccess shape (`lib/concerts/queries.ts`, D-33), applied to
 * a delete instead of a create.
 */
export function useUnlinkStreamingAccount(): UseMutationResult<
  void,
  ApiError,
  string,
  UnlinkContext
> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: string) => {
      await unwrap(async (signal) =>
        apiClient.DELETE("/api/streaming/accounts/{id}", { params: { path: { id } }, signal }),
      );
    },
    onMutate: async (id) => {
      await queryClient.cancelQueries({ queryKey: streamingAccountsQueryKey });
      const previous =
        queryClient.getQueryData<StreamingAccountOutput[]>(streamingAccountsQueryKey);
      queryClient.setQueryData<StreamingAccountOutput[]>(streamingAccountsQueryKey, (old) =>
        removeById(old, id),
      );
      return { previous };
    },
    onError: (_error, _id, context) => {
      restoreIfPresent(queryClient, context);
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: streamingAccountsQueryKey });
    },
  });
}

function restoreIfPresent(queryClient: QueryClient, context: UnlinkContext | undefined): void {
  if (context?.previous) {
    queryClient.setQueryData(streamingAccountsQueryKey, context.previous);
  }
}
