import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor } from "@testing-library/react-native";

const mockPush = jest.fn();
jest.mock("expo-router", () => ({
  useRouter: () => ({ push: mockPush, replace: jest.fn(), back: jest.fn() }),
}));

import { PlaylistSection } from "@/components/playlist";
import { ThemeProvider } from "@/theme";

const originalFetch = global.fetch;

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/ld+json" } });
}

function noBodyResponse(status: number): Response {
  return new Response(null, { status });
}

function collection(member: unknown[]): unknown {
  return { "@id": "/api/x", "@type": "hydra:Collection", totalItems: member.length, member };
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

function renderSection(concertId = "1") {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <PlaylistSection testID="playlist-section" concertId={concertId} />
      </QueryClientProvider>
    </ThemeProvider>,
  );
}

const providersRoute = (member: unknown[] = []): Route => ({
  method: "GET",
  match: (p) => p === "/api/config/providers",
  handler: () => jsonResponse(200, collection(member)),
});
const accountsRoute = (member: unknown[] = []): Route => ({
  method: "GET",
  match: (p) => p === "/api/streaming/accounts",
  handler: () => jsonResponse(200, collection(member)),
});
const jobsRoute = (member: unknown[] = []): Route => ({
  method: "GET",
  match: (p) => p === "/api/playlist-generation-jobs",
  handler: () => jsonResponse(200, collection(member)),
});
const playlistsRoute = (member: unknown[] = []): Route => ({
  method: "GET",
  match: (p) => p === "/api/playlists",
  handler: () => jsonResponse(200, collection(member)),
});

describe("PlaylistSection (US-1, US-7, US-8)", () => {
  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
    mockPush.mockClear();
  });

  it("AC-1.3: no connected+enabled provider — a link-account prompt, not a broken trigger", async () => {
    stubFetch([providersRoute([]), accountsRoute([]), jobsRoute([]), playlistsRoute([])]);
    await renderSection();
    await waitFor(() => expect(screen.getByTestId("generate-playlist-button-link-prompt")).toBeTruthy());
    expect(screen.getByRole("button", { name: "Connect an account" })).toBeTruthy();
  });

  it("AC-1.1/AC-1.2: one candidate — 'Generate playlist' starts a job and navigates to the progress route", async () => {
    stubFetch([
      providersRoute([{ key: "spotify", displayName: "Spotify", enabled: true, isDefault: false }]),
      accountsRoute([{ provider: "spotify", status: "connected" }]),
      jobsRoute([]),
      playlistsRoute([]),
      {
        method: "POST",
        match: (p) => p === "/api/playlist-generation-jobs",
        handler: () =>
          jsonResponse(201, { id: 42, concertId: 1, provider: "spotify", mode: "fast", state: "queued", songsTotal: 0, songsProcessed: 0 }),
      },
    ]);
    await renderSection();
    await waitFor(() => expect(screen.getByTestId("generate-playlist-button")).toBeTruthy());

    await fireEvent.press(screen.getByTestId("generate-playlist-button"));

    await waitFor(() => expect(mockPush).toHaveBeenCalledWith("/concerts/1/playlist"));
  });

  it("T-14: delete confirmation states plainly that the provider-side playlist remains, and a 204 returns to the trigger state", async () => {
    let playlistsCallCount = 0;
    stubFetch([
      providersRoute([{ key: "spotify", displayName: "Spotify", enabled: true, isDefault: true }]),
      accountsRoute([{ provider: "spotify", status: "connected" }]),
      jobsRoute([
        { id: 1, concertId: 1, provider: "spotify", mode: "fast", state: "completed", resultKind: "complete", songsTotal: 19, matchedCount: 19, playlistId: 5 },
      ]),
      {
        method: "GET",
        match: (p) => p === "/api/playlists",
        handler: () => {
          playlistsCallCount += 1;
          const member =
            playlistsCallCount === 1
              ? [{ id: 5, concertId: 1, provider: "spotify", name: "My playlist", externalUrl: "https://open.spotify.com/x", tracks: [] }]
              : [];
          return jsonResponse(200, collection(member));
        },
      },
      {
        method: "DELETE",
        match: (p) => p === "/api/playlists/5",
        handler: () => noBodyResponse(204),
      },
    ]);

    await renderSection();
    await waitFor(() => expect(screen.getByTestId("playlist-delete")).toBeTruthy());

    await fireEvent.press(screen.getByTestId("playlist-delete"));
    expect(screen.getByTestId("delete-playlist-confirmation")).toBeTruthy();
    expect(screen.getByText(/stays in your Spotify account until you delete it there/)).toBeTruthy();

    await fireEvent.press(screen.getByTestId("delete-playlist-confirm"));

    // AC-7.4: after the 204, the concert page returns to the "Generate playlist" trigger state.
    await waitFor(() => expect(screen.getByTestId("generate-playlist-button")).toBeTruthy());
  });
});
