import {
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
  type InfiniteData,
  type QueryClient,
  type UseInfiniteQueryResult,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";

import { apiClient, ApiError, unwrap } from "@/lib/api";

import { formValuesToConcertInput, formValuesToPatchInput, type ConcertFormValues } from "./mapping";
import type { ConcertOutput, ConcertSectionStatus } from "./types";

const PAGE_SIZE = 20; // D-41

/** A concert as it lives in the cache — the same generated shape, plus optimistic-only metadata. */
export type CachedConcert = ConcertOutput & {
  /** D-33: set on the client-generated placeholder row, cleared the moment the real response lands. */
  __tempId?: string;
  __pending?: boolean;
};

export interface ConcertsPage {
  member: CachedConcert[];
  view?: { next?: string | null; [key: string]: unknown };
  totalItems?: number;
}

// AC-8.4 (frontend skeleton): query keys are a tuple starting with a stable domain label.
export const concertsQueryKey = (status: ConcertSectionStatus) => ["concerts", status] as const;
export const concertQueryKey = (id: string) => ["concerts", "detail", id] as const;

function orderForSection(status: ConcertSectionStatus): "asc" | "desc" {
  // AC-1.2: upcoming ascending (soonest first), past descending (most recent first).
  return status === "upcoming" ? "asc" : "desc";
}

async function fetchConcertsPage(status: ConcertSectionStatus, page: number): Promise<ConcertsPage> {
  return unwrap(async (signal) =>
    apiClient.GET("/api/concerts", {
      params: {
        query: {
          status,
          "order[date]": orderForSection(status),
          page,
          itemsPerPage: PAGE_SIZE,
        },
      },
      signal,
    }),
  );
}

/** Reads the next page number off the Hydra `view.next` link — the client never computes it itself. */
function nextPageParam(lastPage: ConcertsPage): number | undefined {
  const next = lastPage.view?.next;
  if (!next) {
    return undefined;
  }
  try {
    const url = new URL(next, "http://placeholder.invalid");
    const page = url.searchParams.get("page");
    return page ? Number(page) : undefined;
  } catch {
    return undefined;
  }
}

/** AC-1.1–AC-1.7, D-32: one of the list's two independent, identically-shaped paginated queries. */
export function useConcertsSection(status: ConcertSectionStatus): UseInfiniteQueryResult<
  InfiniteData<ConcertsPage>,
  ApiError
> {
  return useInfiniteQuery({
    queryKey: concertsQueryKey(status),
    queryFn: ({ pageParam }) => fetchConcertsPage(status, pageParam),
    initialPageParam: 1,
    getNextPageParam: (lastPage) => nextPageParam(lastPage),
    retry: (failureCount, error) => {
      if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
        return false;
      }
      return failureCount < 2;
    },
  });
}

/** US-5/US-11: a single concert by id. A `404` is a genuine `ApiError`, rendered by the caller. */
export function useConcert(id: string): UseQueryResult<ConcertOutput, ApiError> {
  return useQuery({
    queryKey: concertQueryKey(id),
    queryFn: async () =>
      unwrap(async (signal) =>
        apiClient.GET("/api/concerts/{id}", { params: { path: { id } }, signal }),
      ),
    retry: (failureCount, error) => {
      if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
        return false;
      }
      return failureCount < 2;
    },
  });
}

function sectionForDate(date: string): ConcertSectionStatus {
  const today = new Date().toISOString().slice(0, 10);
  return date >= today ? "upcoming" : "past";
}

function buildOptimisticConcert(values: ConcertFormValues, tempId: string): CachedConcert {
  const input = formValuesToConcertInput(values);
  return {
    "@id": `/api/concerts/temp-${tempId}`,
    "@type": "Concert",
    date: input.date ?? undefined,
    timezone: input.timezone ?? undefined,
    status: sectionForDate(values.date),
    lineup: (input.lineup ?? []).map((entry, index) => ({
      band: { name: entry.name ?? undefined },
      billingOrder: index,
    })),
    venue: input.venue ?? undefined,
    ticketPrice: input.ticketPrice,
    doorsTime: input.doorsTime,
    startTime: input.startTime,
    __tempId: tempId,
    __pending: true,
  };
}

function mapPages(
  data: InfiniteData<ConcertsPage> | undefined,
  transform: (members: CachedConcert[]) => CachedConcert[],
): InfiniteData<ConcertsPage> | undefined {
  if (!data) {
    return data;
  }
  return {
    ...data,
    pages: data.pages.map((page, index) =>
      index === 0 ? { ...page, member: transform(page.member) } : page,
    ),
  };
}

function insertOptimistic(queryClient: QueryClient, status: ConcertSectionStatus, optimistic: CachedConcert): void {
  queryClient.setQueryData<InfiniteData<ConcertsPage>>(concertsQueryKey(status), (old) => {
    if (!old) {
      return {
        pages: [{ member: [optimistic], totalItems: 1 }],
        pageParams: [1],
      };
    }
    return mapPages(old, (members) => [optimistic, ...members]);
  });
}

function removeByTempId(queryClient: QueryClient, status: ConcertSectionStatus, tempId: string): void {
  queryClient.setQueryData<InfiniteData<ConcertsPage>>(concertsQueryKey(status), (old) =>
    mapPages(old, (members) => members.filter((member) => member.__tempId !== tempId)),
  );
}

/** D-33: replaces the placeholder wholesale — no field of the optimistic value survives. */
function replaceByTempId(
  queryClient: QueryClient,
  status: ConcertSectionStatus,
  tempId: string,
  real: ConcertOutput,
): boolean {
  let replaced = false;
  queryClient.setQueryData<InfiniteData<ConcertsPage>>(concertsQueryKey(status), (old) =>
    mapPages(old, (members) =>
      members.map((member) => {
        if (member.__tempId === tempId) {
          replaced = true;
          return real;
        }
        return member;
      }),
    ),
  );
  return replaced;
}

export interface CreateConcertContext {
  tempId: string;
  optimisticStatus: ConcertSectionStatus;
}

/**
 * AC-4.1–AC-4.5, D-33: the optimistic card appears immediately, keyed by a client-generated temp
 * id, and on `201` is discarded and replaced wholesale by the server response — never merged with
 * it, because band deduplication (D-25) may change the id and even the stored name.
 */
export function useCreateConcert(): UseMutationResult<
  ConcertOutput,
  ApiError,
  ConcertFormValues,
  CreateConcertContext
> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (values: ConcertFormValues) =>
      unwrap(async (signal) =>
        apiClient.POST("/api/concerts", { body: formValuesToConcertInput(values), signal }),
      ),
    onMutate: async (values) => {
      const tempId = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
      const optimisticStatus = sectionForDate(values.date);
      await queryClient.cancelQueries({ queryKey: concertsQueryKey(optimisticStatus) });
      insertOptimistic(queryClient, optimisticStatus, buildOptimisticConcert(values, tempId));
      return { tempId, optimisticStatus };
    },
    onError: (_error, _values, context) => {
      if (!context) {
        return;
      }
      // AC-4.4: on failure, the optimistic entry is removed — the caller returns the user to the
      // form with their input intact and explains the failure.
      removeByTempId(queryClient, context.optimisticStatus, context.tempId);
    },
    onSuccess: (data, _values, context) => {
      if (!context) {
        return;
      }
      const realStatus = data.status === "past" ? "past" : "upcoming";
      const replacedInGuessedSection = replaceByTempId(
        queryClient,
        context.optimisticStatus,
        context.tempId,
        data,
      );
      if (realStatus !== context.optimisticStatus) {
        // The optimistic today-vs-date guess landed in the wrong section — move it.
        if (replacedInGuessedSection) {
          removeByTempId(queryClient, context.optimisticStatus, context.tempId);
        }
        insertOptimistic(queryClient, realStatus, data);
      }
      queryClient.setQueryData(concertQueryKey(String(data.id)), data);
    },
  });
}

/** AC-6.2–AC-6.5: `PATCH` as JSON merge-patch; on success both the list and detail caches update. */
export function useUpdateConcert(id: string): UseMutationResult<ConcertOutput, ApiError, ConcertFormValues> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (values: ConcertFormValues) =>
      unwrap(async (signal) =>
        apiClient.PATCH("/api/concerts/{id}", {
          params: { path: { id } },
          body: formValuesToPatchInput(values),
          signal,
        }),
      ),
    onSuccess: (data) => {
      queryClient.setQueryData(concertQueryKey(id), data);
      (["upcoming", "past"] as const).forEach((status) => {
        queryClient.setQueryData<InfiniteData<ConcertsPage>>(concertsQueryKey(status), (old) =>
          mapPages(old, (members) => members.map((member) => (String(member.id) === id ? data : member))),
        );
      });
    },
  });
}

/** AC-7.1–AC-7.5: hard delete (D-40) — no optimistic removal, so a failure leaves the row in place. */
export function useDeleteConcert(id: string): UseMutationResult<void, ApiError, void> {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      await unwrap(async (signal) =>
        apiClient.DELETE("/api/concerts/{id}", { params: { path: { id } }, signal }),
      );
    },
    onSuccess: () => {
      queryClient.removeQueries({ queryKey: concertQueryKey(id) });
      (["upcoming", "past"] as const).forEach((status) => {
        queryClient.setQueryData<InfiniteData<ConcertsPage>>(concertsQueryKey(status), (old) =>
          mapPages(old, (members) => members.filter((member) => String(member.id) !== id)),
        );
      });
    },
  });
}
