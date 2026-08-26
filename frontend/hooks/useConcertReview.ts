import {
  useMutation,
  useQuery,
  useQueryClient,
  type QueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";

import { apiClient, ApiError, unwrap } from "@/lib/api";
import { concertQueryKey, concertsQueryKey } from "@/lib/concerts";
import type { ConcertReviewInput, ConcertReviewOutput } from "@/lib/review";

export const concertReviewQueryKey = (concertId: string) => ["concerts", "detail", concertId, "review"] as const;

/**
 * US-1–US-3: `null` (not a thrown error) represents "no review yet" (AC-1.2) — a 404 here is the
 * expected, cacheable steady state, not a failure the caller has to special-case on every render.
 */
export function useConcertReview(concertId: string): UseQueryResult<ConcertReviewOutput | null, ApiError> {
  return useQuery({
    queryKey: concertReviewQueryKey(concertId),
    queryFn: async () => {
      try {
        return await unwrap(async (signal) =>
          apiClient.GET("/api/concerts/{concertId}/review", { params: { path: { concertId } }, signal }),
        );
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) {
          return null;
        }
        throw error;
      }
    },
    retry: (failureCount, error) => {
      if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
        return false;
      }
      return failureCount < 2;
    },
  });
}

/**
 * D-245/AC-6.3/AC-7.4: a save or delete invalidates the review itself, this concert's detail cache,
 * AND both list sections — so the list's `reviewSummary` indicator and the prompt card's
 * eligibility both react without either screen wiring a manual refetch.
 */
function invalidateAfterChange(queryClient: QueryClient, concertId: string): void {
  void queryClient.invalidateQueries({ queryKey: concertReviewQueryKey(concertId) });
  void queryClient.invalidateQueries({ queryKey: concertQueryKey(concertId) });
  (["upcoming", "past"] as const).forEach((status) => {
    void queryClient.invalidateQueries({ queryKey: concertsQueryKey(status) });
  });
}

/**
 * AC-1.5/AC-2.2: one `PUT` for both create and edit — the server decides 201 vs. 200 (D-228); the
 * client never needs to know which. AC-1.6/D-246: on failure this simply rejects — the editor
 * (caller) keeps the user's draft on screen and shows the error, rather than this hook retrying or
 * queueing the write (D-37 stays true, offline is unchanged).
 */
export function useSaveConcertReview(
  concertId: string,
): UseMutationResult<ConcertReviewOutput, ApiError, ConcertReviewInput> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: ConcertReviewInput) =>
      unwrap(async (signal) =>
        apiClient.PUT("/api/concerts/{concertId}/review", {
          params: { path: { concertId } },
          body: input,
          signal,
        }),
      ),
    onSuccess: (data) => {
      queryClient.setQueryData(concertReviewQueryKey(concertId), data);
      invalidateAfterChange(queryClient, concertId);
    },
  });
}

/** AC-2.3/AC-2.4: `204` on success; a concert with no review 404s, same as the singleton read. */
export function useDeleteConcertReview(concertId: string): UseMutationResult<void, ApiError, void> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      await unwrap(async (signal) =>
        apiClient.DELETE("/api/concerts/{concertId}/review", { params: { path: { concertId } }, signal }),
      );
    },
    onSuccess: () => {
      queryClient.setQueryData(concertReviewQueryKey(concertId), null);
      invalidateAfterChange(queryClient, concertId);
    },
  });
}
