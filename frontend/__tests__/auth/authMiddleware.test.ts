/**
 * AC-4.5/AC-4.7 at the HTTP-integration level: stubs `global.fetch` (D-14) and drives the real
 * `apiClient` (with `authHeaderMiddleware`/`refreshRetryMiddleware` wired on, exactly as the app
 * does) through two concurrent 401s, asserting exactly one `/api/token/refresh` call and both
 * original requests succeeding after a single retry each.
 */
jest.mock("@/lib/auth/platform", () => ({
  isNativePlatform: () => false,
  clientPlatformHeader: () => "web",
}));

import { apiClient, unwrap, ApiError } from "@/lib/api";
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
  id: 1,
  email: "person@example.com",
  emailVerified: true,
  roles: ["ROLE_USER"],
  createdAt: "2026-01-01T00:00:00+00:00",
};

const UNAUTHORIZED_BODY = { title: "Unauthorized", status: 401, detail: "Invalid token." };

describe("authMiddleware (AC-4.5/AC-4.7)", () => {
  beforeEach(() => {
    setAccessToken(null);
  });

  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
  });

  it("refreshes exactly once for two concurrent 401s and retries both requests", async () => {
    let refreshCalls = 0;

    global.fetch = jest.fn(async (input: Request | string, init?: RequestInit) => {
      const request = input instanceof Request ? input : new Request(input, init);
      const url = new URL(request.url);

      if (url.pathname === "/api/token/refresh") {
        refreshCalls += 1;
        return jsonResponse(200, {
          "@id": "/api/token/refresh",
          "@type": "Refresh",
          accessToken: "refreshed-token",
          tokenType: "Bearer",
          expiresIn: 900,
          refreshToken: null,
        });
      }

      if (url.pathname === "/api/me") {
        const auth = request.headers.get("Authorization");
        if (auth === "Bearer refreshed-token") {
          return jsonResponse(200, ME_BODY);
        }
        return jsonResponse(401, UNAUTHORIZED_BODY);
      }

      throw new Error(`Unexpected request in test: ${url.pathname}`);
    }) as unknown as typeof fetch;

    const [first, second] = await Promise.all([
      unwrap((signal) => apiClient.GET("/api/me", { signal })),
      unwrap((signal) => apiClient.GET("/api/me", { signal })),
    ]);

    expect(refreshCalls).toBe(1);
    expect(first.email).toBe("person@example.com");
    expect(second.email).toBe("person@example.com");
    expect(getAccessToken()).toBe("refreshed-token");
  });

  it("does not intercept a 401 from /api/login — it's a normal failed-login, not an expired session", async () => {
    global.fetch = jest.fn(async () => jsonResponse(401, UNAUTHORIZED_BODY)) as unknown as typeof fetch;

    await expect(
      unwrap((signal) =>
        apiClient.POST("/api/login", { body: { email: "a@b.com", password: "wrong-password" }, signal }),
      ),
    ).rejects.toBeInstanceOf(ApiError);

    // Exactly one call — no refresh attempt was made for an exempt path.
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });

  it("propagates the original 401 when refresh itself fails, without looping", async () => {
    global.fetch = jest.fn(async (input: Request | string, init?: RequestInit) => {
      const request = input instanceof Request ? input : new Request(input, init);
      const url = new URL(request.url);
      return jsonResponse(401, url.pathname === "/api/token/refresh" ? { title: "Invalid refresh token." } : UNAUTHORIZED_BODY);
    }) as unknown as typeof fetch;

    await expect(unwrap((signal) => apiClient.GET("/api/me", { signal }))).rejects.toBeInstanceOf(ApiError);

    // /api/me once, /api/token/refresh once — no second retry loop.
    expect(global.fetch).toHaveBeenCalledTimes(2);
  });
});
