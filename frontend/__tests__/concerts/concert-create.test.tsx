import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor } from "@testing-library/react-native";

const mockReplace = jest.fn();
const mockBack = jest.fn();
jest.mock("expo-router", () => ({
  useRouter: () => ({ push: jest.fn(), replace: mockReplace, back: mockBack }),
}));

import NewConcertScreen from "@/app/(app)/concerts/new";
import ConcertsListScreen from "@/app/(app)/concerts/index";
import { ThemeProvider } from "@/theme";

const originalFetch = global.fetch;

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/ld+json" } });
}

function collection(member: unknown[]): unknown {
  return { "@id": "/api/concerts", "@type": "hydra:Collection", totalItems: member.length, member };
}

describe("Add concert — optimistic create + reconciliation (US-4, D-33)", () => {
  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
    mockReplace.mockClear();
    mockBack.mockClear();
  });

  it("AC-4.3: replaces the optimistic band name with the server's deduplicated one, never merging", async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    const farFuture = new Date();
    farFuture.setFullYear(farFuture.getFullYear() + 1);
    const dateString = farFuture.toISOString().slice(0, 10);

    let resolvePost!: (r: Response) => void;
    global.fetch = jest.fn(async (input: Request | string) => {
      const request = input instanceof Request ? input : new Request(input);
      if (request.method === "POST") {
        return new Promise<Response>((resolve) => {
          resolvePost = resolve;
        });
      }
      return jsonResponse(200, collection([]));
    }) as unknown as typeof fetch;

    await render(
      <ThemeProvider>
        <QueryClientProvider client={queryClient}>
          <NewConcertScreen />
        </QueryClientProvider>
      </ThemeProvider>,
    );

    await fireEvent.changeText(screen.getByTestId("concert-form-date"), dateString);
    await fireEvent.changeText(screen.getByTestId("concert-form-band-0"), "the beatles");
    await fireEvent.press(screen.getByTestId("concert-form-save"));

    // AC-4.1: the optimistic card lands in the cache immediately, keyed by a temp id, with the
    // TYPED-in name — before the server has answered at all.
    await waitFor(() => {
      const cached = queryClient.getQueryData<{ pages: { member: Record<string, unknown>[] }[] }>([
        "concerts",
        "upcoming",
      ]);
      const member = cached?.pages[0]?.member ?? [];
      expect(member.length).toBe(1);
      expect(member[0].__pending).toBe(true);
    });

    // AC-4.2/AC-4.3: the server dedupes to a differently-cased, canonical name and a real id.
    resolvePost(
      jsonResponse(201, {
        "@id": "/api/concerts/42",
        "@type": "Concert",
        id: 42,
        date: dateString,
        timezone: "Europe/Madrid",
        status: "upcoming",
        lineup: [{ band: { id: 7, name: "Beatles" }, billingOrder: 0 }],
        venue: null,
        ticketPrice: null,
        doorsTime: null,
        startTime: null,
        createdAt: "2026-01-01T00:00:00+00:00",
        updatedAt: "2026-01-01T00:00:00+00:00",
      }),
    );

    await waitFor(() => expect(mockReplace).toHaveBeenCalledWith("/concerts"));

    const cached = queryClient.getQueryData<{ pages: { member: Record<string, unknown>[] }[] }>([
      "concerts",
      "upcoming",
    ]);
    const member = cached?.pages[0]?.member ?? [];
    expect(member.length).toBe(1);
    // The reconciled row IS the server's — no optimistic field survives.
    expect(member[0].__pending).toBeUndefined();
    expect(member[0].id).toBe(42);
    expect((member[0].lineup as { band: { name: string } }[])[0].band.name).toBe("Beatles");
  });

  it("AC-4.4: on failure, the optimistic entry is rolled back and the form keeps the user's input", async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    const farFuture = new Date();
    farFuture.setFullYear(farFuture.getFullYear() + 1);
    const dateString = farFuture.toISOString().slice(0, 10);

    global.fetch = jest.fn(async (input: Request | string) => {
      const request = input instanceof Request ? input : new Request(input);
      if (request.method === "POST") {
        return jsonResponse(500, { title: "Internal Server Error" });
      }
      return jsonResponse(200, collection([]));
    }) as unknown as typeof fetch;

    await render(
      <ThemeProvider>
        <QueryClientProvider client={queryClient}>
          <NewConcertScreen />
        </QueryClientProvider>
      </ThemeProvider>,
    );

    await fireEvent.changeText(screen.getByTestId("concert-form-date"), dateString);
    await fireEvent.changeText(screen.getByTestId("concert-form-band-0"), "Radiohead");
    await fireEvent.press(screen.getByTestId("concert-form-save"));

    await waitFor(() => expect(screen.getByTestId("concert-form-error")).toBeTruthy());

    // The optimistic row was removed from the cache — a failed create leaves no trace.
    const cached = queryClient.getQueryData<{ pages: { member: Record<string, unknown>[] }[] }>([
      "concerts",
      "upcoming",
    ]);
    const member = cached?.pages[0]?.member ?? [];
    expect(member.length).toBe(0);

    // The user's input is still there — nothing was cleared.
    expect(screen.getByTestId("concert-form-band-0").props.value).toBe("Radiohead");
    expect(mockReplace).not.toHaveBeenCalled();
  });

  it("keeps ConcertsListScreen in sync when it shares the same QueryClient as the create flow", async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    global.fetch = jest.fn(async () => jsonResponse(200, collection([]))) as unknown as typeof fetch;

    await render(
      <ThemeProvider>
        <QueryClientProvider client={queryClient}>
          <ConcertsListScreen />
        </QueryClientProvider>
      </ThemeProvider>,
    );

    await waitFor(() => expect(screen.getByTestId("concerts-empty")).toBeTruthy());
  });
});
