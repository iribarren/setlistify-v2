import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react-native";

import { ReviewSection } from "@/components/review";
import type { ConcertOutput } from "@/lib/concerts";
import { countGraphemes } from "@/lib/review";
import { ThemeProvider } from "@/theme";

const originalFetch = global.fetch;

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/ld+json" } });
}

// AC-1.7/AC-9.5/D-237: a guitar emoji, a ZWJ family sequence, CJK, a diacritic, and a would-be
// injection payload — all plain text, none of it ever executed or HTML-escaped-and-mangled.
const ROUNDTRIP_TEXT = "🎸 👨‍👩‍👧‍👦 家族 Sigur Rós <script>alert(1)</script> {{7*7}} '); DROP TABLE concerts;--";

function stubReview(notes: string): void {
  global.fetch = jest.fn(async (input: Request | string) => {
    const request = input instanceof Request ? input : new Request(input);
    const url = new URL(request.url);
    if (request.method === "GET" && url.pathname === "/api/concerts/1/review") {
      return jsonResponse(200, {
        rating: 5,
        notes,
        highlightSongId: null,
        highlightTitle: null,
        createdAt: "2026-01-01T00:00:00+00:00",
        updatedAt: "2026-01-01T00:00:00+00:00",
      });
    }
    throw new Error(`Unexpected request: ${request.method} ${url.pathname}`);
  }) as unknown as typeof fetch;
}

function concert(): ConcertOutput {
  return {
    "@id": "/api/concerts/1",
    "@type": "Concert",
    id: 1,
    date: "2026-01-01",
    timezone: "Europe/Madrid",
    status: "past",
    lineup: [],
    venue: {},
    ticketPrice: null,
    doorsTime: null,
    startTime: null,
    reviewSummary: null,
    createdAt: "2026-01-01T00:00:00+00:00",
    updatedAt: "2026-01-01T00:00:00+00:00",
  } as ConcertOutput;
}

describe("Review notes round trip (AC-1.7, AC-9.4, AC-9.5)", () => {
  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
  });

  it("renders emoji/ZWJ/mixed-script/injection-shaped text byte-for-byte via <Text>, unexecuted and unescaped", async () => {
    stubReview(ROUNDTRIP_TEXT);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await render(
      <ThemeProvider>
        <QueryClientProvider client={queryClient}>
          <ReviewSection testID="review-section" concert={concert()} />
        </QueryClientProvider>
      </ThemeProvider>,
    );

    await waitFor(() => expect(screen.getByTestId("review-section-notes")).toBeTruthy());
    const rendered = screen.getByTestId("review-section-notes");
    expect(rendered.props.children).toBe(ROUNDTRIP_TEXT);
  });

  it("AC-9.2: the grapheme counter treats a ZWJ family sequence as one character, not seven code points", () => {
    expect(countGraphemes("👨‍👩‍👧‍👦")).toBe(1);
  });
});
