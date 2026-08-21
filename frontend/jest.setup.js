// @testing-library/react-native v14 registers its `expect` matchers (toBeVisible,
// toHaveAccessibleName, ...) automatically — no separate extend-expect import needed (AC-10.1).

// AC-1.4/AC-7.2: tests need a base URL for the API client to construct without throwing.
process.env.EXPO_PUBLIC_API_URL = process.env.EXPO_PUBLIC_API_URL ?? "http://localhost:8000";
