import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react-native";

import { ReviewSection } from "@/components/review";
import type { ConcertOutput } from "@/lib/concerts";
import { ThemeProvider } from "@/theme";

const originalFetch = global.fetch;

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/ld+json" } });
}

interface Route {
  method: string;
  match: (pathname: string) => boolean;
  handler: (url: URL) => Response | Promise<Response>;
}

function stubFetch(routes: Route[]): void {
  global.fetch = jest.fn(async (input: Request | string) => {
    const request = input instanceof Request ? input : new Request(input);
    const url = new URL(request.url);
    const route = routes.find((candidate) => candidate.method === request.method && candidate.match(url.pathname));
    if (!route) {
      throw new Error(`Unexpected request: ${request.method} ${url.pathname}`);
    }
    return route.handler(url);
  }) as unknown as typeof fetch;
}

const reviewNotFoundRoute: Route = {
  method: "GET",
  match: (p) => p === "/api/concerts/1/review",
  handler: () => jsonResponse(404, { title: "Not Found", status: 404 }),
};

function reviewFoundRoute(body: Record<string, unknown>): Route {
  return {
    method: "GET",
    match: (p) => p === "/api/concerts/1/review",
    handler: () => jsonResponse(200, body),
  };
}

function baseConcert(overrides: Partial<ConcertOutput> = {}): ConcertOutput {
  return {
    id: 1,
    date: "2026-01-01",
    timezone: "Europe/Madrid",
    status: "past",
    lineup: [{ band: { id: 9, name: "Iceage" }, billingOrder: 0 }],
    venue: {},
    ticketPrice: null,
    doorsTime: null,
    startTime: null,
    reviewSummary: null,
    createdAt: "2026-01-01T00:00:00+00:00",
    updatedAt: "2026-01-01T00:00:00+00:00",
    ...overrides,
  } as ConcertOutput;
}

async function renderSection(concert: ConcertOutput) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <ReviewSection testID="review-section" concert={concert} />
      </QueryClientProvider>
    </ThemeProvider>,
  );
}

describe("ReviewSection (US-1, US-2, US-7)", () => {
  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
  });

  it("AC-1.2: unwritten state is a single affordance — no empty stars, no empty text box", async () => {
    stubFetch([reviewNotFoundRoute]);
    await renderSection(baseConcert());

    await waitFor(() => expect(screen.getByTestId("review-section-write")).toBeTruthy());
    expect(screen.queryByTestId("review-section-rating")).toBeNull();
    expect(screen.queryByTestId("review-section-notes")).toBeNull();
  });

  it("AC-2.1: written state shows the rating, notes and highlight, plus edit/delete", async () => {
    stubFetch([
      reviewFoundRoute({
        rating: 4,
        notes: "Loud and great.",
        highlightSongId: null,
        highlightTitle: "New Brigade",
        createdAt: "2026-01-02T00:00:00+00:00",
        updatedAt: "2026-01-02T00:00:00+00:00",
      }),
    ]);
    await renderSection(baseConcert());

    await waitFor(() => expect(screen.getByTestId("review-section-notes")).toBeTruthy());
    expect(screen.getByText("Loud and great.")).toBeTruthy();
    expect(screen.getByTestId("review-section-highlight")).toHaveTextContent("New Brigade");
    expect(screen.getByTestId("review-section-edit")).toBeTruthy();
    expect(screen.getByTestId("review-section-delete")).toBeTruthy();
  });

  it("AC-1.1/D-234: an upcoming concert renders a de-emphasized panel with no compose affordance", async () => {
    stubFetch([reviewNotFoundRoute]);
    await renderSection(baseConcert({ status: "upcoming" }));

    await waitFor(() => expect(screen.getByTestId("review-section")).toBeTruthy());
    expect(screen.getByText("Unlocks after the show.")).toBeTruthy();
    expect(screen.queryByTestId("review-section-write")).toBeNull();
  });
});
