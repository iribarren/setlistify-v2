import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor } from "@testing-library/react-native";

jest.mock("expo-router", () => ({
  useRouter: () => ({ push: jest.fn(), replace: jest.fn(), back: jest.fn() }),
}));

import NewConcertScreen from "@/app/(app)/concerts/new";
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
        <NewConcertScreen />
      </QueryClientProvider>
    </ThemeProvider>,
  );
}

/**
 * US-8/AC-8.3-AC-8.5: a real 422 `ConstraintViolation` payload, including an indexed lineup path,
 * mapped onto the right field — never rendered as a raw JSON blob.
 */
describe("Add concert — server-side validation display (US-8)", () => {
  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
  });

  it("AC-8.3: highlights the third band row from a `lineup[2].name` violation", async () => {
    await renderScreen();

    const farFuture = new Date();
    farFuture.setFullYear(farFuture.getFullYear() + 1);
    await fireEvent.changeText(screen.getByTestId("concert-form-date"), farFuture.toISOString().slice(0, 10));
    await fireEvent.changeText(screen.getByTestId("concert-form-band-0"), "Headliner Band");
    await fireEvent.press(screen.getByTestId("add-band-button"));
    await fireEvent.press(screen.getByTestId("add-band-button"));
    await fireEvent.changeText(screen.getByTestId("concert-form-band-2"), "x".repeat(200));

    global.fetch = jest.fn(async () =>
      jsonResponse(422, {
        status: 422,
        violations: [
          { propertyPath: "lineup[2].name", message: "Band names are at most 120 characters." },
        ],
      }),
    ) as unknown as typeof fetch;

    await fireEvent.press(screen.getByTestId("concert-form-save"));

    await waitFor(() => expect(screen.getByText("Band names are at most 120 characters.")).toBeTruthy());
    // AC-8.4: no raw JSON/detail/title ever hits the screen.
    expect(screen.queryByText(/"violations"/)).toBeNull();
  });

  it("AC-8.4: an unrecognised violation path lands in the form-level summary", async () => {
    await renderScreen();

    const farFuture = new Date();
    farFuture.setFullYear(farFuture.getFullYear() + 1);
    await fireEvent.changeText(screen.getByTestId("concert-form-date"), farFuture.toISOString().slice(0, 10));
    await fireEvent.changeText(screen.getByTestId("concert-form-band-0"), "Some Band");

    global.fetch = jest.fn(async () =>
      jsonResponse(422, {
        status: 422,
        violations: [{ propertyPath: "owner", message: "Unexpected server-side field." }],
      }),
    ) as unknown as typeof fetch;

    await fireEvent.press(screen.getByTestId("concert-form-save"));

    await waitFor(() => expect(screen.getByTestId("concert-form-summary-error")).toBeTruthy());
    expect(screen.getByText("Unexpected server-side field.")).toBeTruthy();
  });

  it("AC-8.6: fixing the flagged band clears its error without losing the other rows", async () => {
    await renderScreen();

    const farFuture = new Date();
    farFuture.setFullYear(farFuture.getFullYear() + 1);
    await fireEvent.changeText(screen.getByTestId("concert-form-date"), farFuture.toISOString().slice(0, 10));
    await fireEvent.changeText(screen.getByTestId("concert-form-band-0"), "x".repeat(200));

    global.fetch = jest.fn(async () =>
      jsonResponse(422, {
        status: 422,
        violations: [{ propertyPath: "lineup[0].name", message: "Band names are at most 120 characters." }],
      }),
    ) as unknown as typeof fetch;

    await fireEvent.press(screen.getByTestId("concert-form-save"));
    await waitFor(() => expect(screen.getByText("Band names are at most 120 characters.")).toBeTruthy());

    await fireEvent.changeText(screen.getByTestId("concert-form-band-0"), "Fixed Band Name");

    await waitFor(() => expect(screen.queryByText("Band names are at most 120 characters.")).toBeNull());
    expect(screen.getByTestId("concert-form-band-0").props.value).toBe("Fixed Band Name");
  });
});
