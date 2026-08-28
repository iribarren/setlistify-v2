import { useMutation, useQueryClient, type UseMutationResult } from "@tanstack/react-query";

import { apiClient, ApiError, toApiError } from "@/lib/api";

import { setlistRefreshQueryKey } from "./polling";
import type { BandSetlistRefreshOutput } from "./types";

/**
 * `POST /api/bands/{bandId}/setlist-refresh` (AC-1.1). `202`/`200` both resolve normally — a
 * second trigger while one's in flight returns the existing refresh (D-262), never an error. A
 * throttle refusal is a `429` whose BODY is still the full `BandSetlistRefreshOutput` (D-260's
 * status-override mechanism, not a distinct error shape) — the caller reads `refusedReason` off
 * `(error as ApiError).body`, not off a thrown reason string.
 */
export function useTriggerSetlistRefresh(bandId: number): UseMutationResult<BandSetlistRefreshOutput, ApiError, void> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const result = await apiClient.POST("/api/bands/{bandId}/setlist-refresh", {
        params: { path: { bandId: String(bandId) } },
      });
      if (!result.response.ok && result.response.status !== 429) {
        throw toApiError(result.response, result.error);
      }
      // A 429 refusal is still a well-formed BandSetlistRefreshOutput body (D-260) — surface it as
      // the mutation's success value so the caller renders refusal copy from real fields rather than
      // parsing an ApiError. `unwrap()` isn't used here for exactly this reason (mirrors the 304
      // divergence `usePlaylistJobPolling` documents for the same class of problem).
      return (result.data ?? result.error) as BandSetlistRefreshOutput;
    },
    onSuccess: (output) => {
      if (output.bandId != null) {
        queryClient.setQueryData(setlistRefreshQueryKey(output.bandId), output);
      }
    },
  });
}

export interface ResolveBandIdentityVars {
  selectedMbid: string;
}

/**
 * `POST /api/bands/{bandId}/setlist-refresh/resolution` (D-278, AC-6.5). Unlike the trigger, its two
 * refusals (`mbid_not_a_candidate`, `band_already_resolved`) are genuine `422` errors
 * (`SetlistRefreshValidationException`, AC-4.7) with the reason in `ApiError.detail` — never
 * `429`, never retried.
 */
export function useResolveBandIdentity(
  bandId: number,
): UseMutationResult<BandSetlistRefreshOutput, ApiError, ResolveBandIdentityVars> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ selectedMbid }: ResolveBandIdentityVars) => {
      const result = await apiClient.POST("/api/bands/{bandId}/setlist-refresh/resolution", {
        params: { path: { bandId: String(bandId) } },
        body: { selectedMbid },
      });
      if (!result.response.ok) {
        throw toApiError(result.response, result.error);
      }
      return result.data as BandSetlistRefreshOutput;
    },
    onSuccess: (output) => {
      if (output.bandId != null) {
        queryClient.setQueryData(setlistRefreshQueryKey(output.bandId), output);
      }
    },
  });
}

/** AC-4.7 / AC-10.10: narrows an `ApiError` from the resolution pick to its two known refusals. */
export function pickRefusalReason(error: unknown): "mbid_not_a_candidate" | "band_already_resolved" | null {
  if (!(error instanceof ApiError) || error.status !== 422) {
    return null;
  }
  return error.detail === "mbid_not_a_candidate" || error.detail === "band_already_resolved" ? error.detail : null;
}
