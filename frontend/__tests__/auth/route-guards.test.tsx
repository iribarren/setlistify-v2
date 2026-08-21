import React from "react";
import { render, screen } from "@testing-library/react-native";
import { Text } from "react-native";

/**
 * AC-8.3: `(app)` redirects an unauthenticated visitor to `/login`; `(auth)` redirects an
 * authenticated one into `/concerts` (US-9, prompt 07 — Concerts replaced the old `/home` scaffold
 * as the app's real landing route). `useSession` is mocked directly so each branch is exercised in
 * isolation, without a real restore cycle — `SessionProvider.test.tsx` covers the real restore
 * flow this depends on.
 */
const mockUseSession = jest.fn();
jest.mock("@/lib/auth", () => ({
  useSession: () => mockUseSession(),
}));

const mockRedirect = jest.fn((props: { href: string }) => <Text testID="redirect">{props.href}</Text>);
jest.mock("expo-router", () => {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const { Text: RNText } = require("react-native");
  return {
    Redirect: (props: { href: string }) => mockRedirect(props),
    Stack: () => <RNText testID="stack">stack</RNText>,
    Slot: () => <RNText testID="slot">slot</RNText>,
  };
});

// AC-9.1/AC-9.2: the persistent chrome (tab bar / sidebar) is exercised by its own tests
// (nav.test.tsx) — stubbed here so this file stays focused on the redirect guard itself.
jest.mock("@/components/nav", () => {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const { Text: RNText } = require("react-native");
  return {
    BottomTabBar: () => <RNText testID="bottom-tab-bar">tabs</RNText>,
    Sidebar: () => <RNText testID="sidebar">sidebar</RNText>,
    DESKTOP_BREAKPOINT: 900,
  };
});

import AppLayout from "@/app/(app)/_layout";
import AuthLayout from "@/app/(auth)/_layout";
import { ThemeProvider } from "@/theme";

describe("(app)/_layout.tsx route guard (AC-8.3/AC-5.5)", () => {
  afterEach(() => {
    jest.clearAllMocks();
  });

  it("redirects to /login when unauthenticated", async () => {
    mockUseSession.mockReturnValue({ status: "unauthenticated" });
    await render(
      <ThemeProvider>
        <AppLayout />
      </ThemeProvider>,
    );
    expect(screen.getByTestId("redirect").props.children).toBe("/login");
  });

  it("renders the protected shell when authenticated", async () => {
    mockUseSession.mockReturnValue({ status: "authenticated" });
    await render(
      <ThemeProvider>
        <AppLayout />
      </ThemeProvider>,
    );
    expect(screen.getByTestId("slot")).toBeTruthy();
    expect(screen.queryByTestId("redirect")).toBeNull();
  });
});

describe("(auth)/_layout.tsx route guard (AC-8.3)", () => {
  afterEach(() => {
    jest.clearAllMocks();
  });

  it("redirects to /concerts when already authenticated", async () => {
    mockUseSession.mockReturnValue({ status: "authenticated" });
    await render(<AuthLayout />);
    expect(screen.getByTestId("redirect").props.children).toBe("/concerts");
  });

  it("renders the auth stack when unauthenticated", async () => {
    mockUseSession.mockReturnValue({ status: "unauthenticated" });
    await render(<AuthLayout />);
    expect(screen.getByTestId("stack")).toBeTruthy();
    expect(screen.queryByTestId("redirect")).toBeNull();
  });
});
