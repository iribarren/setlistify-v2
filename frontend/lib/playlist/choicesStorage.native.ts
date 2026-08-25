import AsyncStorage from "@react-native-async-storage/async-storage";

/**
 * D-206: native side of the draft-persistence seam — `AsyncStorage`. Resolved via the platform-
 * suffix convention (`tsconfig.json`'s `moduleSuffixes`, same shape as `lib/auth/storage.*.ts`).
 */
export const choicesStorage = {
  getItem(key: string): Promise<string | null> {
    return AsyncStorage.getItem(key);
  },
  setItem(key: string, value: string): Promise<void> {
    return AsyncStorage.setItem(key, value);
  },
  removeItem(key: string): Promise<void> {
    return AsyncStorage.removeItem(key);
  },
};
