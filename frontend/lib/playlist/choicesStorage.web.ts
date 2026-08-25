/**
 * D-206: web side of the draft-persistence seam — `localStorage`, wrapped as async so callers don't
 * branch by platform. Guarded for environments without a `window` (SSR/tests) — a no-op there rather
 * than a throw, mirroring how the rest of this app treats storage as best-effort convenience.
 */
function hasLocalStorage(): boolean {
  try {
    return typeof window !== "undefined" && typeof window.localStorage !== "undefined";
  } catch {
    return false;
  }
}

export const choicesStorage = {
  async getItem(key: string): Promise<string | null> {
    if (!hasLocalStorage()) {
      return null;
    }
    return window.localStorage.getItem(key);
  },
  async setItem(key: string, value: string): Promise<void> {
    if (!hasLocalStorage()) {
      return;
    }
    window.localStorage.setItem(key, value);
  },
  async removeItem(key: string): Promise<void> {
    if (!hasLocalStorage()) {
      return;
    }
    window.localStorage.removeItem(key);
  },
};
