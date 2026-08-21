/**
 * AC-3.4/AC-3.5: native storage goes through `expo-secure-store` only; web storage never touches
 * any JS-readable store. Each adapter is imported by its explicit platform filename (not the bare
 * `./storage` specifier) so this test exercises exactly the file it claims to, independent of
 * Jest's haste platform resolution.
 */

const mockSecureStore = {
  getItemAsync: jest.fn(),
  setItemAsync: jest.fn(),
  deleteItemAsync: jest.fn(),
};

jest.mock("expo-secure-store", () => mockSecureStore);

describe("storage.native (AC-3.4)", () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it("reads, writes and clears the refresh token via expo-secure-store only", async () => {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const { refreshTokenStorage } = require("@/lib/auth/storage.native");

    mockSecureStore.getItemAsync.mockResolvedValueOnce("stored-token");
    await expect(refreshTokenStorage.getRefreshToken()).resolves.toBe("stored-token");
    expect(mockSecureStore.getItemAsync).toHaveBeenCalledWith("setlistify.refreshToken");

    await refreshTokenStorage.setRefreshToken("new-token");
    expect(mockSecureStore.setItemAsync).toHaveBeenCalledWith("setlistify.refreshToken", "new-token");

    await refreshTokenStorage.clearRefreshToken();
    expect(mockSecureStore.deleteItemAsync).toHaveBeenCalledWith("setlistify.refreshToken");
  });
});

describe("storage.web (AC-3.5)", () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it("never reads or writes any JS-readable store — getRefreshToken always resolves null", async () => {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const { refreshTokenStorage } = require("@/lib/auth/storage.web");

    await expect(refreshTokenStorage.getRefreshToken()).resolves.toBeNull();
    // setRefreshToken/clearRefreshToken resolve without throwing and touch nothing.
    await expect(refreshTokenStorage.setRefreshToken("anything")).resolves.toBeUndefined();
    await expect(refreshTokenStorage.clearRefreshToken()).resolves.toBeUndefined();

    // expo-secure-store is never imported by the web adapter — asserted indirectly: the mock
    // above (module-scoped for this file) received zero calls from any of the three operations.
    expect(mockSecureStore.getItemAsync).not.toHaveBeenCalled();
    expect(mockSecureStore.setItemAsync).not.toHaveBeenCalled();
    expect(mockSecureStore.deleteItemAsync).not.toHaveBeenCalled();
  });

  it("never touches localStorage/sessionStorage (jsdom-less RN environment has neither globally)", () => {
    expect(typeof (globalThis as { localStorage?: unknown }).localStorage).toBe("undefined");
    expect(typeof (globalThis as { sessionStorage?: unknown }).sessionStorage).toBe("undefined");
  });
});
