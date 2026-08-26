/**
 * D-242: web side of the dismissal-persistence seam — `localStorage`, wrapped as async so callers
 * don't branch by platform. Guarded for environments without a `window` (SSR/tests): a no-op there
 * rather than a throw, mirroring `lib/playlist/choicesStorage.web.ts`.
 */
function hasLocalStorage(): boolean {
  try {
    return typeof window !== "undefined" && typeof window.localStorage !== "undefined";
  } catch {
    return false;
  }
}

export const reviewPromptStorage = {
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
};
