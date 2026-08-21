import { useQuery, type UseQueryResult } from "@tanstack/react-query";

import type { components } from "@/api";

import { apiClient, unwrap } from "./client";
import { ApiError } from "./errors";

// AC-6.4: no hand-written response type — derived from the generated schema.
export type HealthReport = components["schemas"]["Health.jsonld"];

export interface HealthResult {
  /** Overall status as reported by the backend ("ok" | "error"), defaulting to "error" if absent. */
  status: string;
  database: string;
  redis: string;
  /** True when this result came from a 503 (per-dependency detail) rather than a clean 200. */
  degraded: boolean;
}

// AC-8.4: query key convention — a tuple starting with a stable domain label, so later features
// (concerts, playlists, …) each namespace under their own first segment rather than inventing one.
export const healthQueryKey = ["health"] as const;

function toHealthResult(body: HealthReport, degraded: boolean): HealthResult {
  return {
    status: body.status ?? "error",
    database: body.database ?? "error",
    redis: body.redis ?? "error",
    degraded,
  };
}

/** Runtime narrowing of `ApiError.body` (typed `unknown`, see errors.ts) to the generated shape. */
function isHealthReportShaped(value: unknown): value is HealthReport {
  return typeof value === "object" && value !== null;
}

/**
 * AC-9.1/AC-9.4: fetches `GET /api/health`. A 503 (a dependency down) is not collapsed into a
 * generic failure — API Platform's `HealthOpenApiFactory` documents 503 as returning the SAME
 * `Health.jsonld` shape as 200 (the backend's per-dependency detail, D-6), so this hook reads that
 * body straight off the `ApiError` it produces and returns it as a degraded — not thrown — result.
 * Anything else (network failure, timeout, an unexpected 4xx/5xx) is a genuine `ApiError` the caller
 * renders through `ErrorState` (AC-9.3).
 */
export function useHealth(): UseQueryResult<HealthResult, ApiError> {
  return useQuery({
    queryKey: healthQueryKey,
    queryFn: async () => {
      try {
        const body = await unwrap(async (signal) => apiClient.GET("/api/health", { signal }));
        return toHealthResult(body, false);
      } catch (error) {
        if (error instanceof ApiError && error.status === 503 && isHealthReportShaped(error.body)) {
          return toHealthResult(error.body, true);
        }
        throw error;
      }
    },
    // AC-8.2: sensible defaults, explicit and commented.
    staleTime: 30_000, // health doesn't need refetching more than every 30s while a screen is open
    retry: (failureCount, error) => {
      // Never retry a genuine 4xx client error — retrying won't fix a bad request. Do retry
      // transport failures and 5xx up to 2 extra times with backoff (handled by TanStack's
      // default exponential backoff).
      if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
        return false;
      }
      return failureCount < 2;
    },
  });
}
