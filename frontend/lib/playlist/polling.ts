import { useEffect, useRef } from "react";
import { AppState, type AppStateStatus } from "react-native";
import { useQuery, useQueryClient, type UseQueryResult } from "@tanstack/react-query";

import { apiClient, ApiError, toApiError } from "@/lib/api";

import type { PlaylistGenerationJobOutput } from "./types";

export const playlistJobQueryKey = (jobId: string) => ["playlist", "job", jobId] as const;

/**
 * D-163/D-164/D-165: the whole polling contract, implemented once. `Retry-After` (seconds) seeds
 * `refetchInterval`; its absence is the sole termination signal (D-163) — no state name is
 * special-cased here. `If-None-Match` is sent once an `ETag` has been seen; a 304 keeps the
 * previously cached job untouched (D-164). Backgrounding pauses polling and foregrounding refetches
 * immediately rather than waiting out the interval (D-165, AC-3.3) — `AppState` covers web via
 * React Native Web's own `visibilitychange` bridge, so no platform fork is needed here.
 */
export function usePlaylistJobPolling(jobId: string | null): UseQueryResult<PlaylistGenerationJobOutput | null, ApiError> {
  const queryClient = useQueryClient();
  const etagRef = useRef<string | null>(null);
  const retryAfterMsRef = useRef<number | false>(false);
  const appStateRef = useRef<AppStateStatus>("active");

  const queryKey = playlistJobQueryKey(jobId ?? "");

  const query = useQuery<PlaylistGenerationJobOutput | null, ApiError>({
    queryKey,
    enabled: Boolean(jobId),
    queryFn: async ({ signal }) => {
      if (!jobId) {
        return null;
      }
      const result = await apiClient.GET("/api/playlist-generation-jobs/{id}", {
        params: { path: { id: jobId } },
        headers: etagRef.current ? { "If-None-Match": etagRef.current } : undefined,
        signal,
      });

      const retryAfter = result.response.headers.get("Retry-After");
      // D-163: this is the ENTIRE termination rule — no list of state names to keep in sync.
      retryAfterMsRef.current = retryAfter ? Math.max(1, Number(retryAfter)) * 1000 : false;

      if (result.response.status === 304) {
        // AC-2.4: a 304 re-renders nothing — return the exact same cached reference.
        return queryClient.getQueryData<PlaylistGenerationJobOutput | null>(queryKey) ?? null;
      }

      const etag = result.response.headers.get("ETag");
      if (etag) {
        etagRef.current = etag;
      }

      if (!result.response.ok) {
        throw toApiError(result.response, result.error);
      }
      return (result.data as PlaylistGenerationJobOutput) ?? null;
    },
    // AC-2.3: seeded by the server's Retry-After; `false` (no header) stops polling entirely.
    refetchInterval: () => (appStateRef.current === "active" ? retryAfterMsRef.current : false),
    refetchOnWindowFocus: false,
    // A failed poll is not a failed generation — keep showing the last known progress (§2 "Errors").
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
      if (nextState === "active" && wasInactive && jobId) {
        // AC-3.3: foregrounding refetches immediately rather than waiting out the interval.
        void query.refetch();
      }
    });
    return () => subscription.remove();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- one-shot subscription per job id.
  }, [jobId]);

  return query;
}
