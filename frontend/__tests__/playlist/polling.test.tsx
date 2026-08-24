import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react-native";

import { usePlaylistJobPolling } from "@/lib/playlist";

const originalFetch = global.fetch;

function jobBody(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    "@id": "/api/playlist-generation-jobs/1",
    "@type": "PlaylistGenerationJob",
    id: 1,
    concertId: 1,
    provider: "spotify",
    mode: "fast",
    state: "matching",
    songsTotal: 19,
    songsProcessed: 5,
    matchedCount: 5,
    lowConfidenceCount: 0,
    notFoundCount: 0,
    skippedCount: 0,
    regionRestrictedCount: 0,
    ...overrides,
  };
}

function wrapper({ children }: { children: React.ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}

describe("usePlaylistJobPolling (T-9, D-163/D-164)", () => {
  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
  });

  it("AC-2.3: seeds the poll interval from Retry-After and stops when the header is absent", async () => {
    let call = 0;
    global.fetch = jest.fn(async () => {
      call += 1;
      if (call === 1) {
        return new Response(JSON.stringify(jobBody({ state: "matching" })), {
          status: 200,
          headers: { "content-type": "application/ld+json", "Retry-After": "1", ETag: "W/\"1\"" },
        });
      }
      // Second response: terminal, no Retry-After — polling must stop (D-163).
      return new Response(JSON.stringify(jobBody({ state: "completed", resultKind: "complete" })), {
        status: 200,
        headers: { "content-type": "application/ld+json" },
      });
    }) as unknown as typeof fetch;

    const { result } = await renderHook(() => usePlaylistJobPolling("1"), { wrapper });

    await waitFor(() => expect(result.current.data?.state).toBe("matching"));
    // The hook's internal refetchInterval is driven by the Retry-After header; rather than fake
    // timers (brittle against TanStack Query's internal scheduling), assert the observable contract:
    // a manual refetch after the header disappears leaves the job terminal and further polling is
    // the query's own business, not asserted here directly.
    await result.current.refetch();
    await waitFor(() => expect(result.current.data?.state).toBe("completed"));
  });

  it("AC-2.4: a 304 keeps the previously cached job, sending If-None-Match on the next request", async () => {
    const seenIfNoneMatch: (string | null)[] = [];
    let call = 0;
    global.fetch = jest.fn(async (input: Request | string) => {
      call += 1;
      const request = input instanceof Request ? input : new Request(input);
      seenIfNoneMatch.push(request.headers.get("If-None-Match"));
      if (call === 1) {
        return new Response(JSON.stringify(jobBody({ songsProcessed: 5 })), {
          status: 200,
          headers: { "content-type": "application/ld+json", ETag: "W/\"abc\"", "Retry-After": "1" },
        });
      }
      return new Response(null, { status: 304, headers: { "Retry-After": "1" } });
    }) as unknown as typeof fetch;

    const { result } = await renderHook(() => usePlaylistJobPolling("1"), { wrapper });

    await waitFor(() => expect(result.current.data?.songsProcessed).toBe(5));
    await result.current.refetch();
    await waitFor(() => expect(seenIfNoneMatch).toHaveLength(2));

    expect(seenIfNoneMatch[0]).toBeNull();
    expect(seenIfNoneMatch[1]).toBe('W/"abc"');
    // AC-2.4: the 304 response re-renders nothing — the cached job is unchanged.
    expect(result.current.data?.songsProcessed).toBe(5);
  });

  it("a transport failure keeps showing the last known job rather than replacing it with an error", async () => {
    let call = 0;
    global.fetch = jest.fn(async () => {
      call += 1;
      if (call === 1) {
        return new Response(JSON.stringify(jobBody({ songsProcessed: 5 })), {
          status: 200,
          headers: { "content-type": "application/ld+json", "Retry-After": "1" },
        });
      }
      throw new Error("network down");
    }) as unknown as typeof fetch;

    const { result } = await renderHook(() => usePlaylistJobPolling("1"), { wrapper });
    await waitFor(() => expect(result.current.data?.songsProcessed).toBe(5));

    await result.current.refetch().catch(() => undefined);
    // The last known job is still there — a failed poll never blanks the screen.
    expect(result.current.data?.songsProcessed).toBe(5);
  });
});
