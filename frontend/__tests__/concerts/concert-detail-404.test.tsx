import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor } from "@testing-library/react-native";

const mockReplace = jest.fn();
let mockCurrentId = "999";
jest.mock("expo-router", () => ({
  useRouter: () => ({ push: jest.fn(), replace: mockReplace, back: jest.fn() }),
  useLocalSearchParams: () => ({ id: mockCurrentId }),
}));

import ConcertDetailScreen from "@/app/(app)/concerts/[id]/index";
import { ThemeProvider } from "@/theme";

const originalFetch = global.fetch;

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/problem+json" } });
}

function renderScreen() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <ConcertDetailScreen />
      </QueryClientProvider>
    </ThemeProvider>,
  );
}

/**
 * US-11/AC-11.4: a 404 for a deleted concert, an unknown id and another user's id (D-27's
 * ownership-filtered query extension) all reach the client as the exact same HTTP response — this
 * asserts the client renders them identically, with no "forbidden"/"not yours" wording anywhere.
 */
describe("ConcertDetailScreen — 404 equivalence (US-11)", () => {
  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
    mockReplace.mockClear();
  });

  it("renders the ordinary not-found state for an unknown id", async () => {
    mockCurrentId = "999";
    global.fetch = jest.fn(async () => jsonResponse(404, { title: "Not Found", status: 404 })) as unknown as typeof fetch;

    await renderScreen();

    await waitFor(() => expect(screen.getByTestId("concert-detail-not-found")).toBeTruthy());
    expect(screen.getByText("This concert couldn't be found.")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Back to concerts" })).toBeTruthy();
  });

  it("renders the identical not-found state for another user's (owner-filtered) id", async () => {
    mockCurrentId = "42";
    // D-27: the API returns 404, never 403, for a concert owned by someone else — same status,
    // same body shape as a genuinely unknown id.
    global.fetch = jest.fn(async () => jsonResponse(404, { title: "Not Found", status: 404 })) as unknown as typeof fetch;

    await renderScreen();

    await waitFor(() => expect(screen.getByTestId("concert-detail-not-found")).toBeTruthy());
    expect(screen.getByText("This concert couldn't be found.")).toBeTruthy();

    // AC-11.2: no "forbidden"/"not allowed"/"not yours"/"permission" wording anywhere.
    expect(screen.queryByText(/forbidden/i)).toBeNull();
    expect(screen.queryByText(/not yours/i)).toBeNull();
    expect(screen.queryByText(/permission/i)).toBeNull();
  });

  it("'Back to concerts' navigates to the list", async () => {
    mockCurrentId = "999";
    global.fetch = jest.fn(async () => jsonResponse(404, { title: "Not Found", status: 404 })) as unknown as typeof fetch;

    await renderScreen();
    await waitFor(() => expect(screen.getByTestId("concert-detail-not-found")).toBeTruthy());

    await fireEvent.press(screen.getByRole("button", { name: "Back to concerts" }));
    expect(mockReplace).toHaveBeenCalledWith("/concerts");
  });
});
