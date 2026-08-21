import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react-native";

import HealthScreen from "@/app/index";
import type { components } from "@/api";
import { ThemeProvider } from "@/theme";

// AC-10.5/D-14: stub HTTP at the transport boundary so the test exercises the real
// openapi-fetch client, real RFC 7807 parsing and the real generated types.
const originalFetch = global.fetch;

// AC-10.6: fixtures are typed from the generated schema, never a hand-written response type.
type HealthBody = components["schemas"]["Health.jsonld"];

// JSON-LD envelope fields every Hydra item response carries — present on the fixtures below only
// to satisfy the generated type; the screen itself never reads them (AC-9.1).
const JSONLD_ENVELOPE = { "@id": "/api/health", "@type": "Health" };

const HEALTHY_FIXTURE: HealthBody = { ...JSONLD_ENVELOPE, status: "ok", database: "ok", redis: "ok" };
const DEGRADED_FIXTURE: HealthBody = {
  ...JSONLD_ENVELOPE,
  status: "error",
  database: "ok",
  redis: "error",
};

function jsonResponse(status: number, body: HealthBody): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/ld+json" },
  });
}

function renderScreen() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <HealthScreen />
      </QueryClientProvider>
    </ThemeProvider>,
  );
}

describe("health screen (US-9)", () => {
  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
  });

  it("shows LoadingState while the request is in flight (AC-9.2)", async () => {
    let resolveFetch!: (value: Response) => void;
    global.fetch = jest.fn(
      () =>
        new Promise<Response>((resolve) => {
          resolveFetch = resolve;
        }),
    ) as unknown as typeof fetch;

    await renderScreen();

    expect(screen.getByText("Checking backend health")).toBeTruthy();

    // Resolve so the test doesn't leave a dangling promise/timer behind.
    resolveFetch(jsonResponse(200, HEALTHY_FIXTURE));
    await waitFor(() => expect(screen.queryByText("Checking backend health")).toBeNull());
  });

  it("renders real 200 values through the typed client (AC-9.1)", async () => {
    global.fetch = jest.fn(async () => jsonResponse(200, HEALTHY_FIXTURE)) as unknown as typeof fetch;

    await renderScreen();

    await waitFor(() => expect(screen.getByText("All systems healthy")).toBeTruthy());
    expect(screen.getAllByText("ok").length).toBeGreaterThanOrEqual(3);
  });

  it("shows ErrorState with a working retry when the backend is unreachable (AC-9.3)", async () => {
    global.fetch = jest.fn(async () => {
      throw new TypeError("Network request failed");
    }) as unknown as typeof fetch;

    await renderScreen();

    // AC-8.2: a transport failure retries with backoff before settling to an error (useHealth's
    // own retry policy, not the QueryClient default) — so this genuinely takes longer than 1s.
    await waitFor(() => expect(screen.getByRole("alert")).toBeTruthy(), { timeout: 8000 });
    expect(screen.getByRole("button", { name: "Try again" })).toBeTruthy();
  });

  it("shows per-dependency detail on a 503, not a generic error (AC-9.4)", async () => {
    global.fetch = jest.fn(async () => jsonResponse(503, DEGRADED_FIXTURE)) as unknown as typeof fetch;

    await renderScreen();

    await waitFor(() => expect(screen.getByText("Backend degraded")).toBeTruthy());
    // The healthy dependency is still shown, not collapsed into a generic failure.
    expect(screen.getByText("1 / 2")).toBeTruthy();
    // No alert role — a 503 with per-dependency detail is DegradedState, never ErrorState (AC-5.3).
    expect(screen.queryByRole("alert")).toBeNull();
  });
});
