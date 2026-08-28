import { useEffect, useRef } from "react";
import { AppState, type AppStateStatus } from "react-native";
import { useQuery, type UseQueryResult } from "@tanstack/react-query";

import { apiClient, ApiError, toApiError } from "@/lib/api";
import { retryAfterMs } from "@/lib/api/pollingHelpers";

import type { BandSetlistRefreshOutput } from "./types";

export const setlistRefreshQueryKey = (bandId: number) => ["setlistRefresh", bandId] as const;

/**
 * AC-10.4: reuses the exact `Retry-After` contract `usePlaylistJobPolling`
 * (`lib/playlist/polling.ts`) established — `retryAfterMs()` (`lib/api/pollingHelpers.ts`) is the
 * shared piece, so this is the same mechanism, not a second one. No `ETag`/304 handling here:
 * `BandSetlistRefreshProvider` doesn't support conditional GET (unlike the playlist job poller), so
 * every poll re-fetches the full body. The `AppState` foreground-refetch effect below is
 * `usePlaylistJobPolling`'s own pattern, not `useForegroundRefetch` — `refetchInterval` reads
 * `appStateRef.current` synchronously while `useQuery` constructs its `QueryObserver`, so the ref
 * must exist before that call, not after it (learned the hard way: `useForegroundRefetch`, which
 * needs `query.refetch` and so can only be called after `useQuery`, crashed with "Cannot read
 * properties of undefined (reading 'current')" the first time this was tried).
 */
export function useSetlistRefreshPolling(bandId: number | null): UseQueryResult<BandSetlistRefreshOutput | null, ApiError> {
  const retryAfterMsRef = useRef<number | false>(false);
  const appStateRef = useRef<AppStateStatus>("active");
  const queryKey = setlistRefreshQueryKey(bandId ?? 0);

  const query = useQuery<BandSetlistRefreshOutput | null, ApiError>({
    queryKey,
    enabled: bandId != null,
    queryFn: async ({ signal }) => {
      if (bandId == null) {
        return null;
      }
      const result = await apiClient.GET("/api/bands/{bandId}/setlist-refresh", {
        params: { path: { bandId: String(bandId) } },
        signal,
      });

      retryAfterMsRef.current = retryAfterMs(result.response.headers.get("Retry-After"));

      if (!result.response.ok) {
        throw toApiError(result.response, result.error);
      }
      return (result.data as BandSetlistRefreshOutput) ?? null;
    },
    refetchInterval: () => (appStateRef.current === "active" ? retryAfterMsRef.current : false),
    refetchOnWindowFocus: false,
    retry: (failureCount, error) => {
      if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
        return false;
      }
      return failureCount < 3;
    },
  });

  useEffect(() => {
    const subscription = AppState.addEventListener("change", (nextState) => {
      const wasInactive = appStateRef.current !== "active";
      appStateRef.current = nextState;
      if (nextState === "active" && wasInactive && bandId != null) {
        void query.refetch();
      }
    });
    return () => subscription.remove();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- one-shot subscription per band id.
  }, [bandId]);

  return query;
}
