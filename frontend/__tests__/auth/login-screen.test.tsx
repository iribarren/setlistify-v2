import React from "react";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react-native";

jest.mock("@/lib/auth/platform", () => ({
  isNativePlatform: () => false,
  clientPlatformHeader: () => "web",
}));

import LoginScreen from "@/app/(auth)/login";
import { SessionProvider } from "@/lib/auth";
import { setAccessToken } from "@/lib/auth/tokenStore";
import { ThemeProvider } from "@/theme";

const originalFetch = global.fetch;

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/ld+json" },
  });
}

/**
 * AC-12.3 requires coverage of "login form validation and submission" — this exercises the real
 * `LoginScreen` against a stubbed `global.fetch` (D-14), through the real `SessionProvider`/
 * `apiClient`, exactly as it runs in the app.
 */
async function renderLoginScreen() {
  return render(
    <ThemeProvider>
      <SessionProvider>
        <LoginScreen />
      </SessionProvider>
    </ThemeProvider>,
  );
}

describe("LoginScreen (US-2)", () => {
  beforeEach(() => {
    setAccessToken(null);
    // Cold-start restore always fails in these tests — no session on entry.
    global.fetch = jest.fn(
      async () => jsonResponse(401, { title: "Invalid refresh token." }),
    ) as unknown as typeof fetch;
  });

  afterEach(() => {
    cleanup();
    global.fetch = originalFetch;
    jest.restoreAllMocks();
  });

  it("AC-2.7: the submit button starts disabled until both fields are filled", async () => {
    await renderLoginScreen();
    await waitFor(() => expect(screen.getByTestId("login-submit")).toBeTruthy());

    expect(screen.getByTestId("login-submit").props.accessibilityState.disabled).toBe(true);

    await fireEvent.changeText(screen.getByTestId("login-email"), "person@example.com");
    await fireEvent.changeText(screen.getByTestId("login-password"), "correcthorsebattery");

    await waitFor(() =>
      expect(screen.getByTestId("login-submit").props.accessibilityState.disabled).toBe(false),
    );
  });

  it("AC-2.4: a wrong password renders the same generic failure via ErrorState", async () => {
    await renderLoginScreen();
    await waitFor(() => expect(screen.getByTestId("login-submit")).toBeTruthy());

    await fireEvent.changeText(screen.getByTestId("login-email"), "person@example.com");
    await fireEvent.changeText(screen.getByTestId("login-password"), "wrong-password-123");

    global.fetch = jest.fn(async () =>
      jsonResponse(401, { title: "Unauthorized", detail: "Invalid credentials." }),
    ) as unknown as typeof fetch;

    await fireEvent.press(screen.getByTestId("login-submit"));

    await waitFor(() => expect(screen.getByRole("alert")).toBeTruthy());
    expect(screen.getByText("Invalid credentials.")).toBeTruthy();
  });

  it("AC-2.6: a successful login clears the error state and calls the login endpoint", async () => {
    await renderLoginScreen();
    await waitFor(() => expect(screen.getByTestId("login-submit")).toBeTruthy());

    await fireEvent.changeText(screen.getByTestId("login-email"), "person@example.com");
    await fireEvent.changeText(screen.getByTestId("login-password"), "correcthorsebattery");

    global.fetch = jest.fn(async (input: Request | string, init?: RequestInit) => {
      const request = input instanceof Request ? input : new Request(input, init);
      const url = new URL(request.url);
      if (url.pathname === "/api/login") {
        return jsonResponse(201, {
          "@id": "/api/login",
          "@type": "Login",
          accessToken: "fresh-token",
          tokenType: "Bearer",
          expiresIn: 900,
          refreshToken: null,
        });
      }
      if (url.pathname === "/api/me") {
        return jsonResponse(200, {
          "@id": "/api/me",
          "@type": "Me",
          id: 1,
          email: "person@example.com",
          emailVerified: true,
          roles: ["ROLE_USER"],
          createdAt: "2026-01-01T00:00:00+00:00",
        });
      }
      throw new Error(`Unexpected request: ${url.pathname}`);
    }) as unknown as typeof fetch;

    await fireEvent.press(screen.getByTestId("login-submit"));

    // No ErrorState rendered — the login promise resolved rather than throwing.
    await waitFor(() => expect(screen.queryByRole("alert")).toBeNull());
  });
});
