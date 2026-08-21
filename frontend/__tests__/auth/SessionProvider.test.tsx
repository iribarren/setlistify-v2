import React from "react";
import { act, cleanup, render, screen, waitFor } from "@testing-library/react-native";
import { Text } from "react-native";

jest.mock("@/lib/auth/platform", () => ({
  isNativePlatform: () => false,
  clientPlatformHeader: () => "web",
}));

import { SessionProvider, useSession } from "@/lib/auth/SessionProvider";
import { emitSessionExpired } from "@/lib/auth/sessionEvents";
import { getAccessToken, setAccessToken } from "@/lib/auth/tokenStore";

const originalFetch = global.fetch;

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/ld+json" },
  });
}

const ME_BODY = {
  "@id": "/api/me",
  "@type": "Me",
  id: 7,
  email: "restored@example.com",
  emailVerified: false,
  roles: ["ROLE_USER"],
  createdAt: "2026-01-01T00:00:00+00:00",
};

function Probe(): React.JSX.Element {
  const { status, user } = useSession();
  return <Text testID="probe">{`${status}:${user?.email ?? "none"}`}</Text>;
}

async function renderProvider() {
  return render(
    <SessionProvider>
      <Probe />
    </SessionProvider>,
  );
}

// Each SessionProvider mount subscribes to the module-level `sessionEvents` pub/sub (unsubscribing
// on unmount) — an explicit cleanup between tests (rather than relying on RNTL's implicit default)
// keeps that subscription list from leaking a stale listener into the next test.
afterEach(() => {
  cleanup();
});

describe("SessionProvider — cold-start restore (US-3)", () => {
  beforeEach(() => {
    setAccessToken(null);
  });

  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
  });

  it("AC-3.1/AC-3.2: shows restoring, then lands authenticated once the cookie refresh resolves", async () => {
    let resolveRefresh!: (response: Response) => void;
    global.fetch = jest.fn(async (input: Request | string, init?: RequestInit) => {
      const request = input instanceof Request ? input : new Request(input, init);
      const url = new URL(request.url);
      if (url.pathname === "/api/token/refresh") {
        return new Promise<Response>((resolve) => {
          resolveRefresh = resolve;
        });
      }
      if (url.pathname === "/api/me") {
        return jsonResponse(200, ME_BODY);
      }
      throw new Error(`Unexpected request: ${url.pathname}`);
    }) as unknown as typeof fetch;

    await renderProvider();

    // The refresh call hasn't resolved yet — restore is still in flight (AC-3.1).
    expect(screen.getByTestId("probe").props.children).toBe("restoring:none");

    resolveRefresh(
      jsonResponse(200, {
        "@id": "/api/token/refresh",
        "@type": "Refresh",
        accessToken: "restored-token",
        tokenType: "Bearer",
        expiresIn: 900,
        refreshToken: null,
      }),
    );

    await waitFor(() =>
      expect(screen.getByTestId("probe").props.children).toBe("authenticated:restored@example.com"),
    );
    expect(getAccessToken()).toBe("restored-token");
  });

  it("AC-3.3: lands cleanly on unauthenticated — no error thrown — when there is no valid session", async () => {
    global.fetch = jest.fn(
      async () => jsonResponse(401, { title: "Invalid refresh token." }),
    ) as unknown as typeof fetch;

    await renderProvider();

    await waitFor(() => expect(screen.getByTestId("probe").props.children).toBe("unauthenticated:none"));
    expect(getAccessToken()).toBeNull();
  });
});

describe("SessionProvider — login (US-2/AC-2.6)", () => {
  beforeEach(() => {
    setAccessToken(null);
  });

  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
  });

  it("moves status to authenticated and loads the user after a successful login", async () => {
    function LoginProbe(): React.JSX.Element {
      const session = useSession();
      return (
        <>
          <Text testID="probe">{`${session.status}:${session.user?.email ?? "none"}`}</Text>
          <Text
            testID="login-action"
            onPress={() => void session.login("person@example.com", "correcthorsebattery")}
          >
            login
          </Text>
        </>
      );
    }

    // Cold-start restore fails first (no session yet) — same as any first launch without a cookie.
    global.fetch = jest.fn(
      async () => jsonResponse(401, { title: "Invalid refresh token." }),
    ) as unknown as typeof fetch;

    const view = await render(
      <SessionProvider>
        <LoginProbe />
      </SessionProvider>,
    );

    await waitFor(() => expect(view.getByTestId("probe").props.children).toBe("unauthenticated:none"));

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
        return jsonResponse(200, { ...ME_BODY, email: "person@example.com" });
      }
      throw new Error(`Unexpected request: ${url.pathname}`);
    }) as unknown as typeof fetch;

    view.getByTestId("login-action").props.onPress();

    await waitFor(() =>
      expect(view.getByTestId("probe").props.children).toBe("authenticated:person@example.com"),
    );
  });
});

describe("SessionProvider — background refresh failure (AC-4.7)", () => {
  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
  });

  it("routes to unauthenticated exactly once when a background refresh ultimately fails", async () => {
    global.fetch = jest.fn(async (input: Request | string, init?: RequestInit) => {
      const request = input instanceof Request ? input : new Request(input, init);
      const url = new URL(request.url);
      if (url.pathname === "/api/token/refresh") {
        return jsonResponse(200, {
          "@id": "/api/token/refresh",
          "@type": "Refresh",
          accessToken: "session-token",
          tokenType: "Bearer",
          expiresIn: 900,
          refreshToken: null,
        });
      }
      if (url.pathname === "/api/me") {
        return jsonResponse(200, ME_BODY);
      }
      throw new Error(`Unexpected request: ${url.pathname}`);
    }) as unknown as typeof fetch;

    setAccessToken(null);
    const view = await render(
      <SessionProvider>
        <Probe />
      </SessionProvider>,
    );
    await waitFor(() =>
      expect(view.getByTestId("probe").props.children).toBe("authenticated:restored@example.com"),
    );

    // A later, unrelated 401 whose refresh also fails should flip status to unauthenticated once —
    // this test drives that reaction directly (refreshCoordinator.test.ts covers the emission side).
    await act(async () => {
      emitSessionExpired();
    });

    await waitFor(() => expect(view.getByTestId("probe").props.children).toBe("unauthenticated:none"));
  });
});
