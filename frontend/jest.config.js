// AC-10.1: jest-expo preset + React Native Testing Library.
module.exports = {
  preset: "jest-expo",
  setupFilesAfterEnv: ["<rootDir>/jest.setup.js"],
  // useHealth's own retry-with-backoff (AC-8.2) means the network-failure test genuinely takes a
  // few seconds to settle — give every test room rather than special-casing one file.
  testTimeout: 12000,
  // AC-4.6: lucide-react-native's package.json "react-native"/"exports" condition points Jest's
  // resolver at its ESM (.mjs) build, which Jest's transform pipeline does not handle by
  // extension. Its CommonJS build is functionally identical, so tests resolve there instead —
  // this does not affect what ships to the app (Metro resolves the ESM build normally).
  moduleNameMapper: {
    "^lucide-react-native$": "<rootDir>/node_modules/lucide-react-native/dist/cjs/lucide-react-native.js",
  },
  // Generated output (AC-6.7) and Expo Router's own file-based routes are not unit-tested here.
  collectCoverageFrom: ["**/*.{ts,tsx}", "!api/**", "!**/node_modules/**", "!**/.expo/**"],
  testPathIgnorePatterns: ["/node_modules/", "/.expo/"],
};
