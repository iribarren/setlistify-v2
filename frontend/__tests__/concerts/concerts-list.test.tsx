import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor } from "@testing-library/react-native";

const mockPush = jest.fn();
jest.mock("expo-router", () => ({
  useRouter: () => ({ push: mockPush, replace: jest.fn(), back: jest.fn() }),
}));

import ConcertsListScreen from "@/app/(app)/concerts/index";
import { ThemeProvider } from "@/theme";

const originalFetch = global.fetch;

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/ld+json" } });
}

function collection(member: unknown[]): unknown {
  return { "@id": "/api/concerts", "@type": "hydra:Collection", totalItems: member.length, member };
}

function concertFixture(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    "@id": "/api/concerts/1",
    "@type": "Concert",
    id: 1,
    date: "2026-09-05",
    timezone: "Europe/Madrid",
    status: "upcoming",
    lineup: [{ band: { id: 1, name: "Iceage" }, billingOrder: 0 }],
    venue: { name: "The Garage", city: "Glasgow", countryCode: "GB" },
    ticketPrice: { amount: 1600, currency: "GBP" },
    doorsTime: null,
    startTime: null,
    createdAt: "2026-01-01T00:00:00+00:00",
    updatedAt: "2026-01-01T00:00:00+00:00",
    ...overrides,
  };
}

function renderScreen() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <ConcertsListScreen />
      </QueryClientProvider>
    </ThemeProvider>,
  );
}

function stubFetchByStatus(handlers: { upcoming: () => Response; past: () => Response }): void {
  global.fetch = jest.fn(async (input: Request | string) => {
    const request = input instanceof Request ? input : new Request(input);
    const url = new URL(request.url);
    const status = url.searchParams.get("status");
    if (status === "upcoming") return handlers.upcoming();
    if (status === "past") return handlers.past();
    throw new Error(`Unexpected status filter: ${status}`);
  }) as unknown as typeof fetch;
}

describe("ConcertsListScreen (US-1/US-2, AC-12.5)", () => {
  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
    mockPush.mockClear();
  });

  it("AC-1.4: shows skeleton cards while the first page loads", async () => {
    // A shared gate every request awaits before building its OWN fresh Response — a Response's
    // body can only be read once, so the upcoming and past queries can't share one instance, but
    // they can both wait on the same release.
    let releaseGate!: () => void;
    const gate = new Promise<void>((resolve) => {
      releaseGate = resolve;
    });
    global.fetch = jest.fn(async () => {
      await gate;
      return jsonResponse(200, collection([]));
    }) as unknown as typeof fetch;

    await renderScreen();
    expect(screen.getByTestId("concerts-loading")).toBeTruthy();

    releaseGate();
    await waitFor(() => expect(screen.queryByTestId("concerts-loading")).toBeNull());
  });

  it("AC-2.1: renders the designed empty state when both sections are empty", async () => {
    stubFetchByStatus({
      upcoming: () => jsonResponse(200, collection([])),
      past: () => jsonResponse(200, collection([])),
    });

    await renderScreen();

    await waitFor(() => expect(screen.getByTestId("concerts-empty")).toBeTruthy());
    expect(screen.getByText("No concerts yet")).toBeTruthy();
  });

  it("AC-1.1-AC-1.3/AC-1.7: renders populated sections, and an inline empty line for an empty section", async () => {
    stubFetchByStatus({
      upcoming: () => jsonResponse(200, collection([concertFixture()])),
      past: () => jsonResponse(200, collection([])),
    });

    await renderScreen();

    await waitFor(() => expect(screen.getByTestId("concerts-list")).toBeTruthy());
    expect(screen.getByText("Iceage")).toBeTruthy();
    expect(screen.getByText("The Garage, Glasgow")).toBeTruthy();
    // AC-1.7: the Past section still renders its OWN empty line rather than disappearing.
    expect(screen.getByTestId("past-section-empty")).toBeTruthy();
    expect(screen.getByText("No past concerts yet")).toBeTruthy();
  });

  it("AC-1.8: a list-level failure with nothing cached renders the designed error state with retry", async () => {
    stubFetchByStatus({
      upcoming: () => jsonResponse(500, { title: "Internal Server Error" }),
      past: () => jsonResponse(500, { title: "Internal Server Error" }),
    });

    await renderScreen();

    await waitFor(() => expect(screen.getByTestId("concerts-error")).toBeTruthy(), { timeout: 8000 });
    expect(screen.getByText("Couldn't load your concerts.")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Try again" })).toBeTruthy();
  });

  it("navigates to /concerts/new from the empty state's primary action", async () => {
    stubFetchByStatus({
      upcoming: () => jsonResponse(200, collection([])),
      past: () => jsonResponse(200, collection([])),
    });

    await renderScreen();
    await waitFor(() => expect(screen.getByTestId("concerts-empty")).toBeTruthy());

    await fireEvent.press(screen.getByRole("button", { name: "Add concert" }));
    expect(mockPush).toHaveBeenCalledWith("/concerts/new");
  });
});
