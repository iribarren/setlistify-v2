import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor } from "@testing-library/react-native";

const mockReplace = jest.fn();
let mockRef: string | undefined;
jest.mock("expo-router", () => ({
  useRouter: () => ({ push: jest.fn(), replace: mockReplace, back: jest.fn() }),
  useLocalSearchParams: () => ({ ref: mockRef }),
}));

const mockOpenAuthSessionAsync = jest.fn();
jest.mock("expo-web-browser", () => ({
  openAuthSessionAsync: (...args: unknown[]) => mockOpenAuthSessionAsync(...args),
}));

import { ConnectionsSection } from "@/components/streaming";
import { ThemeProvider } from "@/theme";

const originalFetch = global.fetch;

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/ld+json" } });
}

function noBodyResponse(status: number): Response {
  return new Response(null, { status });
}

function collection(member: unknown[]): unknown {
  return { "@id": "/api/streaming/accounts", "@type": "hydra:Collection", totalItems: member.length, member };
}

function accountFixture(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    "@id": "/api/streaming/accounts/1",
    "@type": "StreamingAccount",
    id: 1,
    provider: "spotify",
    providerDisplayName: "Spotify",
    providerAccountId: "spotify_user_1",
    scopes: ["user-read-private", "playlist-modify-private"],
    linkedAt: "2026-08-01T00:00:00+00:00",
    status: "connected",
    ...overrides,
  };
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

function renderSection() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <ConnectionsSection />
      </QueryClientProvider>
    </ThemeProvider>,
  );
}

describe("ConnectionsSection (US-1, US-2, US-3, US-5)", () => {
  beforeEach(() => {
    mockRef = undefined;
  });

  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
    mockReplace.mockClear();
    mockOpenAuthSessionAsync.mockClear();
  });

  it("AC-2.6: shows an explicit empty state with a connect action when nothing is linked", async () => {
    stubFetch([
      { method: "GET", match: (p) => p === "/api/streaming/accounts", handler: () => jsonResponse(200, collection([])) },
    ]);

    await renderSection();

    await waitFor(() => expect(screen.getByTestId("connections-empty")).toBeTruthy());
    expect(screen.getByRole("button", { name: "Connect Spotify" })).toBeTruthy();
  });

  it("AC-2.1/AC-2.5: renders a connected account with its own status badge", async () => {
    stubFetch([
      {
        method: "GET",
        match: (p) => p === "/api/streaming/accounts",
        handler: () => jsonResponse(200, collection([accountFixture()])),
      },
    ]);

    await renderSection();

    await waitFor(() => expect(screen.getByTestId("connection-spotify")).toBeTruthy());
    expect(screen.getByText("Spotify")).toBeTruthy();
    expect(screen.getByText("Connected")).toBeTruthy();
    // A connected account offers Disconnect but no Reconnect.
    expect(screen.getByRole("button", { name: "Disconnect" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Reconnect" })).toBeNull();
  });

  it("AC-5.3: a needs_reauth account renders an explicit Reconnect affordance", async () => {
    stubFetch([
      {
        method: "GET",
        match: (p) => p === "/api/streaming/accounts",
        handler: () => jsonResponse(200, collection([accountFixture({ status: "needs_reauth" })])),
      },
    ]);

    await renderSection();

    await waitFor(() => expect(screen.getByText("Needs reconnect")).toBeTruthy());
    expect(screen.getByRole("button", { name: "Reconnect" })).toBeTruthy();
  });

  it("US-1: connecting starts the link, opens the auth session, and resolves the returned ref into a refreshed list", async () => {
    let accountsCallCount = 0;
    stubFetch([
      {
        method: "GET",
        match: (p) => p === "/api/streaming/accounts",
        handler: () => {
          accountsCallCount += 1;
          const member = accountsCallCount === 1 ? [] : [accountFixture()];
          return jsonResponse(200, collection(member));
        },
      },
      {
        method: "POST",
        match: (p) => p === "/api/streaming/link",
        handler: () =>
          jsonResponse(201, {
            "@id": "/api/streaming/link/1",
            "@type": "StreamingLink",
            authorizationUrl: "https://accounts.spotify.com/authorize?client_id=abc",
          }),
      },
      {
        method: "GET",
        match: (p) => p === "/api/streaming/link-results/abc123",
        handler: () =>
          jsonResponse(200, {
            "@id": "/api/streaming/link-results/abc123",
            "@type": "StreamingLinkResult",
            provider: "spotify",
            success: true,
            reason: null,
          }),
      },
    ]);
    mockOpenAuthSessionAsync.mockResolvedValue({ type: "success", url: "setlistify://account?ref=abc123" });

    await renderSection();
    await waitFor(() => expect(screen.getByTestId("connections-empty")).toBeTruthy());

    await fireEvent.press(screen.getByRole("button", { name: "Connect Spotify" }));

    // AC-1.1: the authorization URL comes from the backend — never assembled client-side. This
    // asserts the mock received exactly the URL the (mocked) `POST /api/streaming/link` returned.
    await waitFor(() => expect(mockOpenAuthSessionAsync).toHaveBeenCalledWith(
      "https://accounts.spotify.com/authorize?client_id=abc",
      "setlistify://account",
    ));

    await waitFor(() => expect(screen.getByTestId("connection-spotify")).toBeTruthy());
    expect(screen.getByText("Connected")).toBeTruthy();
  });

  it("AC-1.10: a dismissed/cancelled auth session leaves the account state unchanged", async () => {
    stubFetch([
      {
        method: "GET",
        match: (p) => p === "/api/streaming/accounts",
        handler: () => jsonResponse(200, collection([])),
      },
      {
        method: "POST",
        match: (p) => p === "/api/streaming/link",
        handler: () =>
          jsonResponse(201, {
            "@id": "/api/streaming/link/1",
            "@type": "StreamingLink",
            authorizationUrl: "https://accounts.spotify.com/authorize?client_id=abc",
          }),
      },
    ]);
    mockOpenAuthSessionAsync.mockResolvedValue({ type: "dismiss" });

    await renderSection();
    await waitFor(() => expect(screen.getByTestId("connections-empty")).toBeTruthy());

    await fireEvent.press(screen.getByRole("button", { name: "Connect Spotify" }));

    await waitFor(() => expect(mockOpenAuthSessionAsync).toHaveBeenCalled());
    // Still the empty state — nothing was resolved, no link-results call was ever made (stubFetch
    // would have thrown on an unexpected request if one had been attempted).
    expect(screen.getByTestId("connections-empty")).toBeTruthy();
  });

  it("AC-1.7: resolves an opaque `ref` carried on this route's own params after the web redirect, then strips it from the URL", async () => {
    mockRef = "web-ref-1";
    let resultsCalled = false;
    stubFetch([
      {
        method: "GET",
        match: (p) => p === "/api/streaming/accounts",
        handler: () => jsonResponse(200, collection([accountFixture()])),
      },
      {
        method: "GET",
        match: (p) => p === "/api/streaming/link-results/web-ref-1",
        handler: () => {
          resultsCalled = true;
          return jsonResponse(200, {
            "@id": "/api/streaming/link-results/web-ref-1",
            "@type": "StreamingLinkResult",
            provider: "spotify",
            success: true,
            reason: null,
          });
        },
      },
    ]);

    await renderSection();

    await waitFor(() => expect(resultsCalled).toBe(true));
    // AC-8.7: single-use — the ref is stripped from the URL so a refresh can't resolve it again.
    await waitFor(() => expect(mockReplace).toHaveBeenCalledWith("/account"));
  });

  it("US-3/AC-3.6: disconnect asks for confirmation, removes the row optimistically, and shows Spotify's revocation follow-up (D-81)", async () => {
    let deleteCalled = false;
    let accountsCallCount = 0;
    stubFetch([
      {
        method: "GET",
        match: (p) => p === "/api/streaming/accounts",
        handler: () => {
          accountsCallCount += 1;
          const member = accountsCallCount === 1 ? [accountFixture()] : [];
          return jsonResponse(200, collection(member));
        },
      },
      {
        method: "DELETE",
        match: (p) => p === "/api/streaming/accounts/1",
        handler: () => {
          deleteCalled = true;
          return noBodyResponse(204);
        },
      },
    ]);

    await renderSection();
    await waitFor(() => expect(screen.getByTestId("connection-spotify")).toBeTruthy());

    await fireEvent.press(screen.getByRole("button", { name: "Disconnect" }));
    expect(screen.getByTestId("disconnect-confirmation")).toBeTruthy();

    await fireEvent.press(screen.getByTestId("disconnect-confirm"));

    // Optimistic: the row disappears without waiting on anything further, and the confirmation goes.
    await waitFor(() => expect(screen.queryByTestId("connection-spotify")).toBeNull());
    expect(screen.queryByTestId("disconnect-confirmation")).toBeNull();
    expect(deleteCalled).toBe(true);

    await waitFor(() => expect(screen.getByTestId("revocation-notice")).toBeTruthy());
    expect(screen.getByText(/remove Setlistify from your Spotify account settings/)).toBeTruthy();
  });

  it("US-3/AC-3.6: a failed disconnect restores the row (rollback) and shows an error", async () => {
    stubFetch([
      {
        method: "GET",
        match: (p) => p === "/api/streaming/accounts",
        handler: () => jsonResponse(200, collection([accountFixture()])),
      },
      {
        method: "DELETE",
        match: (p) => p === "/api/streaming/accounts/1",
        handler: () => jsonResponse(500, { title: "Internal Server Error" }),
      },
    ]);

    await renderSection();
    await waitFor(() => expect(screen.getByTestId("connection-spotify")).toBeTruthy());

    await fireEvent.press(screen.getByRole("button", { name: "Disconnect" }));
    await fireEvent.press(screen.getByTestId("disconnect-confirm"));

    // Removed optimistically, then restored once the DELETE comes back as a failure.
    await waitFor(() => expect(screen.getByTestId("connection-spotify")).toBeTruthy());
    expect(screen.getByTestId("unlink-error")).toBeTruthy();
  });
});
