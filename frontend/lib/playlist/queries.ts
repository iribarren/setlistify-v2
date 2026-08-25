import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";

import { apiClient, ApiError, unwrap } from "@/lib/api";

import { clearPlaylistChoiceDraft } from "./choices";
import { playlistJobQueryKey } from "./polling";
import { ACTIVE_JOB_STATES, asJobState } from "./types";
import type {
  CandidateSetlistsOutput,
  PendingChoicesOutput,
  PlaylistGenerationJobOutput,
  PlaylistOutput,
  ProviderConfigOutput,
  SetlistChoiceInput,
  StartGenerationInput,
  VersionChoicesInput,
} from "./types";

// AC-8.4 (frontend skeleton pattern): stable domain-labelled query key tuples.
export const providersQueryKey = ["playlist", "providers"] as const;
export const concertJobsQueryKey = (concertId: string) => ["playlist", "jobs", "concert", concertId] as const;
export const concertPlaylistsQueryKey = (concertId: string) =>
  ["playlist", "playlists", "concert", concertId] as const;
export const playlistDetailQueryKey = (playlistId: string) => ["playlist", "detail", playlistId] as const;

/**
 * D-169's last line: unauthenticated, `no-store`, short client-side `staleTime` (Resolved question 4:
 * 60s) so an operator's mid-incident toggle reaches an open app quickly, plus a refetch on foreground
 * and before every generation start (handled by the caller via `refetch()`/`invalidateQueries`).
 */
export function useProviderConfigs(): UseQueryResult<ProviderConfigOutput[], ApiError> {
  return useQuery({
    queryKey: providersQueryKey,
    queryFn: async () => {
      const body = await unwrap(async (signal) => apiClient.GET("/api/config/providers", { signal }));
      return body.member ?? [];
    },
    staleTime: 60_000,
  });
}

async function fetchConcertJobs(concertId: string): Promise<PlaylistGenerationJobOutput[]> {
  return unwrap(async (signal) =>
    apiClient.GET("/api/playlist-generation-jobs", {
      params: { query: { concertId: Number(concertId) } },
      signal,
    }),
  ).then((body) => body.member ?? []);
}

function byCreatedAtDesc(a: PlaylistGenerationJobOutput, b: PlaylistGenerationJobOutput): number {
  return (b.createdAt ?? "").localeCompare(a.createdAt ?? "");
}

/**
 * AC-3.2: resolves "the current job" for a concert — an active job wins over a blocked one, which
 * wins over the most recent terminal one, mirroring §2's "Resolution on entry" priority. Returns
 * `null` when the concert has no job at all (the ordinary pre-generation state).
 */
export function pickCurrentJob(jobs: PlaylistGenerationJobOutput[]): PlaylistGenerationJobOutput | null {
  if (jobs.length === 0) {
    return null;
  }
  const sorted = [...jobs].sort(byCreatedAtDesc);
  const active = sorted.find((job) => ACTIVE_JOB_STATES.includes(asJobState(job.state) ?? "queued"));
  if (active) {
    return active;
  }
  const blocked = sorted.find((job) => asJobState(job.state) === "blocked");
  if (blocked) {
    return blocked;
  }
  return sorted[0];
}

/** AC-3.2: resolves the current job for a concert on mount, from anywhere, including a cold start. */
export function useConcertPlaylistJobs(concertId: string): UseQueryResult<PlaylistGenerationJobOutput[], ApiError> {
  return useQuery({
    queryKey: concertJobsQueryKey(concertId),
    queryFn: () => fetchConcertJobs(concertId),
  });
}

/** AC-8.4: the ONLY source for the concert page's playlist section — never derived from a job response. */
export function useConcertPlaylists(concertId: string): UseQueryResult<PlaylistOutput[], ApiError> {
  return useQuery({
    queryKey: concertPlaylistsQueryKey(concertId),
    queryFn: async () => {
      const body = await unwrap(async (signal) =>
        apiClient.GET("/api/playlists", { params: { query: { concertId: Number(concertId) } }, signal }),
      );
      return body.member ?? [];
    },
  });
}

/** The full playlist detail — name, description, externalUrl, matchRate, report and every track. */
export function usePlaylistDetail(playlistId: string | null): UseQueryResult<PlaylistOutput, ApiError> {
  return useQuery({
    queryKey: playlistDetailQueryKey(playlistId ?? ""),
    enabled: Boolean(playlistId),
    queryFn: async () =>
      unwrap(async (signal) =>
        apiClient.GET("/api/playlists/{id}", { params: { path: { id: playlistId as string } }, signal }),
      ),
  });
}

export interface StartGenerationVars {
  concertId: string;
  provider?: string;
  /** D-203: "Choose it yourself" starts a `mode: "normal"` job (AC-1.1). Omitted — Fast, as before. */
  mode?: "fast" | "normal";
  /** AC-4.3: the expired job a fresh one pre-fills from (US-4). */
  resumeFromJobId?: string;
}

/**
 * AC-1.2: a 201 (new job) and a 200 (an already-live job, D-129) are handled identically — both are
 * simply the job to navigate to. `unwrap()` already treats any 2xx as success, so no branching is
 * needed here.
 */
export function useStartGeneration(): UseMutationResult<PlaylistGenerationJobOutput, ApiError, StartGenerationVars> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ concertId, provider, mode, resumeFromJobId }: StartGenerationVars) => {
      const body: StartGenerationInput = {
        concertId: Number(concertId),
        provider: provider ?? null,
        mode: mode ?? null,
        resumeFromJobId: resumeFromJobId ? Number(resumeFromJobId) : null,
      };
      return unwrap(async (signal) => apiClient.POST("/api/playlist-generation-jobs", { body, signal }));
    },
    onSuccess: (job, { concertId }) => {
      queryClient.setQueryData(playlistJobQueryKey(String(job.id)), job);
      void queryClient.invalidateQueries({ queryKey: concertJobsQueryKey(concertId) });
    },
  });
}

// --- Normal mode (docs/specs/2026-08-25-playlist-normal-mode.md, D-190) ---------------------------

export const candidateSetlistsQueryKey = (jobId: string) => ["playlist", "candidate-setlists", jobId] as const;
export const pendingChoicesQueryKey = (jobId: string) => ["playlist", "pending-choices", jobId] as const;

/** US-1, step 1: AC-1.2's projection of `candidateSetlists` — zero setlist.fm calls (AC-1.2). */
export function useCandidateSetlists(jobId: string | null): UseQueryResult<CandidateSetlistsOutput, ApiError> {
  return useQuery({
    queryKey: candidateSetlistsQueryKey(jobId ?? ""),
    enabled: Boolean(jobId),
    queryFn: async () =>
      unwrap(async (signal) =>
        apiClient.GET("/api/playlist-generation-jobs/{id}/candidate-setlists", {
          params: { path: { id: jobId as string } },
          signal,
        }),
      ),
  });
}

export interface SubmitSetlistChoiceVars {
  jobId: string;
  input: SetlistChoiceInput;
}

/**
 * T-05: `awaiting_setlist_choice -> matching`. AC-6.5: the caller is responsible for treating a 422
 * as "refetch the job and re-render from server truth" — this hook only performs the call.
 */
export function useSubmitSetlistChoice(): UseMutationResult<PlaylistGenerationJobOutput, ApiError, SubmitSetlistChoiceVars> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ jobId, input }: SubmitSetlistChoiceVars) =>
      unwrap(async (signal) =>
        apiClient.POST("/api/playlist-generation-jobs/{id}/setlist-choice", {
          params: { path: { id: jobId } },
          body: input,
          signal,
        }),
      ),
    onSuccess: (job, { jobId }) => {
      queryClient.setQueryData(playlistJobQueryKey(jobId), job);
    },
  });
}

/** US-2, step 2: AC-2.4's projection of `pendingChoices` — no provider search happens here. */
export function usePendingChoices(jobId: string | null): UseQueryResult<PendingChoicesOutput, ApiError> {
  return useQuery({
    queryKey: pendingChoicesQueryKey(jobId ?? ""),
    enabled: Boolean(jobId),
    queryFn: async () =>
      unwrap(async (signal) =>
        apiClient.GET("/api/playlist-generation-jobs/{id}/pending-choices", {
          params: { path: { id: jobId as string } },
          signal,
        }),
      ),
  });
}

export interface SubmitVersionChoicesVars {
  jobId: string;
  input: VersionChoicesInput;
}

/**
 * T-08: `awaiting_version_choice -> building`. D-192: full replacement, idempotent while still
 * suspended — re-submitting (e.g. the user goes back and changes an answer) simply overwrites.
 * D-194: this is also literally "Build the playlist" on the client-side confirm step.
 */
export function useSubmitVersionChoices(): UseMutationResult<PlaylistGenerationJobOutput, ApiError, SubmitVersionChoicesVars> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ jobId, input }: SubmitVersionChoicesVars) =>
      unwrap(async (signal) =>
        apiClient.POST("/api/playlist-generation-jobs/{id}/version-choices", {
          params: { path: { id: jobId } },
          body: input,
          signal,
        }),
      ),
    onSuccess: (job, { jobId }) => {
      queryClient.setQueryData(playlistJobQueryKey(jobId), job);
      // D-206: the draft's job is over — clear it on successful submission.
      void clearPlaylistChoiceDraft(jobId);
    },
  });
}

/** D-208: "Start over" — cancel the suspended job, then the caller starts a fresh one. */
export function useCancelGeneration(jobId: string): UseMutationResult<PlaylistGenerationJobOutput, ApiError, void> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () =>
      unwrap(async (signal) =>
        apiClient.POST("/api/playlist-generation-jobs/{id}/cancel", { params: { path: { id: jobId } }, signal }),
      ),
    onSuccess: (job) => {
      queryClient.setQueryData(playlistJobQueryKey(jobId), job);
      void clearPlaylistChoiceDraft(jobId);
    },
  });
}

/** AC-7.1: offered only on a `failed` job (D-172) — the caller enforces that, this just calls the endpoint. */
export function useRetryGeneration(jobId: string): UseMutationResult<PlaylistGenerationJobOutput, ApiError, void> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () =>
      unwrap(async (signal) =>
        apiClient.POST("/api/playlist-generation-jobs/{id}/retry", { params: { path: { id: jobId } }, signal }),
      ),
    onSuccess: (job) => {
      queryClient.setQueryData(playlistJobQueryKey(jobId), job);
    },
  });
}

/** AC-7.2/T-13: offered only for `failureReason = creation_indeterminate`. */
export function useCreateAnyway(jobId: string): UseMutationResult<PlaylistGenerationJobOutput, ApiError, void> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () =>
      unwrap(async (signal) =>
        apiClient.POST("/api/playlist-generation-jobs/{id}/create-anyway", {
          params: { path: { id: jobId } },
          signal,
        }),
      ),
    onSuccess: (job) => {
      queryClient.setQueryData(playlistJobQueryKey(jobId), job);
    },
  });
}

/** AC-7.4: on 204, the caller returns the concert page to the trigger state. */
export function useDeletePlaylist(concertId: string): UseMutationResult<void, ApiError, string> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (playlistId: string) => {
      await unwrap(async (signal) =>
        apiClient.DELETE("/api/playlists/{id}", { params: { path: { id: playlistId } }, signal }),
      );
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: concertPlaylistsQueryKey(concertId) });
      void queryClient.invalidateQueries({ queryKey: concertJobsQueryKey(concertId) });
    },
  });
}
