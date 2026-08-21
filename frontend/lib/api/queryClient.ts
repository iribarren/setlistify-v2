import { QueryClient } from "@tanstack/react-query";

import { ApiError } from "./errors";

/**
 * AC-8.1/AC-8.2: one `QueryClient` for the app, created in `app/_layout.tsx`. Defaults are set
 * explicitly here (not left at the library default) and commented, per D-12 — TanStack Query owns
 * all server state; there is no separate client-state store yet.
 */
export function createAppQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        // Data is "fresh enough" for 30s by default — most screens here are read-mostly. Individual
        // hooks (e.g. useHealth) may override this.
        staleTime: 30_000,
        // Never retry a 4xx — it will fail the same way again. Retry everything else (network
        // failures, timeouts, 5xx) up to twice with TanStack's default exponential backoff.
        retry: (failureCount, error) => {
          if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
            return false;
          }
          return failureCount < 2;
        },
        // AC-9.3: a stopped/unreachable backend must not spin forever — refetch-on-focus/reconnect
        // stay on (the library default) so a retry after the user reopens the app is automatic.
      },
    },
  });
}
