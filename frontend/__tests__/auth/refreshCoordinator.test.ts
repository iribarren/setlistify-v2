/**
 * AC-4.5: N concurrent callers must produce exactly ONE `/api/token/refresh` call, with every
 * caller resolving off the same in-flight promise. Isolated from the HTTP/middleware stack by
 * mocking `lib/auth/api`'s `refresh()` directly — `authMiddleware.test.ts` covers the same
 * guarantee at the HTTP-integration level.
 */
jest.mock("@/lib/auth/platform", () => ({
  isNativePlatform: () => false,
  clientPlatformHeader: () => "web",
}));

const mockRefresh = jest.fn();
jest.mock("@/lib/auth/api", () => ({
  refresh: (...args: unknown[]) => mockRefresh(...args),
}));

import { performRefresh } from "@/lib/auth/refreshCoordinator";
import { getAccessToken, setAccessToken } from "@/lib/auth/tokenStore";
import { onSessionExpired } from "@/lib/auth/sessionEvents";

describe("performRefresh (AC-4.5)", () => {
  beforeEach(() => {
    mockRefresh.mockReset();
    setAccessToken(null);
  });

  it("joins concurrent callers into exactly one underlying refresh call", async () => {
    let resolveRefresh!: (value: { accessToken: string; expiresIn: number; refreshToken: null }) => void;
    mockRefresh.mockReturnValueOnce(
      new Promise((resolve) => {
        resolveRefresh = resolve;
      }),
    );

    const p1 = performRefresh();
    const p2 = performRefresh();
    const p3 = performRefresh();

    resolveRefresh({ accessToken: "new-access-token", expiresIn: 900, refreshToken: null });

    const results = await Promise.all([p1, p2, p3]);

    expect(mockRefresh).toHaveBeenCalledTimes(1);
    expect(results.every((r) => r.accessToken === "new-access-token")).toBe(true);
    expect(getAccessToken()).toBe("new-access-token");
  });

  it("starts a fresh refresh once the previous one has settled", async () => {
    mockRefresh
      .mockResolvedValueOnce({ accessToken: "first", expiresIn: 900, refreshToken: null })
      .mockResolvedValueOnce({ accessToken: "second", expiresIn: 900, refreshToken: null });

    await performRefresh();
    await performRefresh();

    expect(mockRefresh).toHaveBeenCalledTimes(2);
    expect(getAccessToken()).toBe("second");
  });

  it("clears the access token and emits sessionExpired when refresh fails (AC-4.7)", async () => {
    setAccessToken("stale-token");
    mockRefresh.mockRejectedValueOnce(new Error("Invalid refresh token."));

    const expired = jest.fn();
    const unsubscribe = onSessionExpired(expired);

    await expect(performRefresh()).rejects.toThrow("Invalid refresh token.");

    expect(getAccessToken()).toBeNull();
    expect(expired).toHaveBeenCalledTimes(1);

    unsubscribe();
  });
});
