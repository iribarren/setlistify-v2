import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor } from "@testing-library/react-native";

import { SetlistRefreshAction } from "@/components/playlist";
import { ThemeProvider } from "@/theme";

const originalFetch = global.fetch;

function refreshBody(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    "@id": "/api/bands/42/setlist-refresh",
    "@type": "BandSetlistRefresh",
    bandId: 42,
    state: null,
    requestedAt: null,
    finishedAt: null,
    bandResolutionStateBefore: "ambiguous",
    bandResolutionStateAfter: null,
    freshness: { source: "cache", fetchedAt: null, stale: false, reason: null, budgetResetAt: null },
    cooldownUntil: null,
    candidates: [],
    refusedReason: null,
    retryAfterAt: null,
    ...overrides,
  };
}

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { "content-type": "application/ld+json" },
    ...init,
  });
}

function renderAction(onBandResolved: () => void = () => undefined) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <SetlistRefreshAction testID="refresh" bandId={42} bandName="Boikot" onBandResolved={onBandResolved} />
      </ThemeProvider>
    </QueryClientProvider>,
  );
}

describe("SetlistRefreshAction (US-10)", () => {
  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
  });

  it("AC-10.3: shows a disabled control naming the cooldown's return time, not a hidden control", async () => {
    global.fetch = jest.fn(async () =>
      jsonResponse(
        refreshBody({
          state: "succeeded",
          bandResolutionStateAfter: "unresolved",
          cooldownUntil: new Date(Date.now() + 3_600_000).toISOString(),
        }),
      ),
    ) as unknown as typeof fetch;

    await renderAction();

    await waitFor(() => expect(screen.getByTestId("refresh-trigger")).toBeTruthy());
    expect(screen.getByTestId("refresh-trigger").props.accessibilityState?.disabled).toBe(true);
  });

  it("AC-10.5: shows distinct copy naming the return time when the trigger is refused", async () => {
    let call = 0;
    global.fetch = jest.fn(async () => {
      call += 1;
      if (call === 1) {
        return jsonResponse(refreshBody());
      }
      // The POST refusal — status 429, body is still the full output (D-260).
      return jsonResponse(
        refreshBody({ refusedReason: "daily_limit_reached", retryAfterAt: "2026-08-28T00:00:00+00:00" }),
        { status: 429 },
      );
    }) as unknown as typeof fetch;

    await renderAction();

    await waitFor(() => expect(screen.getByTestId("refresh-trigger")).toBeTruthy());
    fireEvent.press(screen.getByTestId("refresh-trigger"));

    await waitFor(() => expect(screen.queryByTestId("refresh-trigger")).toBeNull());
    expect(screen.getByText(/used today's refreshes/i)).toBeTruthy();
  });

  it("AC-10.6/AC-10.8: an ambiguous outcome lists candidates and requires a confirm step before picking", async () => {
    global.fetch = jest.fn(async () =>
      jsonResponse(
        refreshBody({
          state: "succeeded",
          bandResolutionStateAfter: "ambiguous",
          candidates: [
            { mbid: "mbid-1", name: "Boikot", sortName: "Boikot", disambiguation: "Spanish ska-punk band" },
            { mbid: "mbid-2", name: "Boikot", sortName: "Boikot", disambiguation: "French metal band" },
          ],
        }),
      ),
    ) as unknown as typeof fetch;

    await renderAction();

    await waitFor(() => expect(screen.getByTestId("refresh-candidate-mbid-1")).toBeTruthy());
    // No submit before a candidate is chosen — the confirmation panel isn't rendered yet.
    expect(screen.queryByTestId("refresh-confirm-submit")).toBeNull();

    fireEvent.press(screen.getByTestId("refresh-candidate-mbid-1"));

    await waitFor(() => expect(screen.getByTestId("refresh-confirm-submit")).toBeTruthy());
    expect(screen.getByText(/every user/i)).toBeTruthy();
  });

  it("AC-10.10: band_already_resolved is framed as a normal outcome and refetches the result", async () => {
    let call = 0;
    global.fetch = jest.fn(async () => {
      call += 1;
      if (call === 1) {
        return jsonResponse(
          refreshBody({
            state: "succeeded",
            bandResolutionStateAfter: "ambiguous",
            candidates: [{ mbid: "mbid-1", name: "Boikot", sortName: "Boikot", disambiguation: "Spanish ska-punk band" }],
          }),
        );
      }
      // The pick — refused, band already resolved by someone else.
      return new Response(
        JSON.stringify({ title: "Setlist refresh pick refused", detail: "band_already_resolved", status: 422 }),
        { status: 422, headers: { "content-type": "application/problem+json" } },
      );
    }) as unknown as typeof fetch;

    const onBandResolved = jest.fn();
    await renderAction(onBandResolved);

    await waitFor(() => expect(screen.getByTestId("refresh-candidate-mbid-1")).toBeTruthy());
    fireEvent.press(screen.getByTestId("refresh-candidate-mbid-1"));
    await waitFor(() => expect(screen.getByTestId("refresh-confirm-submit")).toBeTruthy());
    fireEvent.press(screen.getByTestId("refresh-confirm-submit"));

    await waitFor(() => expect(screen.getByTestId("refresh-pick-error")).toBeTruthy());
    expect(screen.getByText(/someone else/i)).toBeTruthy();
    expect(onBandResolved).toHaveBeenCalled();
  });
});
