import React from "react";
import { render, screen } from "@testing-library/react-native";
import { Text } from "react-native";

/**
 * AC-8.3: `(app)` redirects an unauthenticated visitor to `/login`; `(auth)` redirects an
 * authenticated one into `/home`. `useSession` is mocked directly so each branch is exercised in
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
  };
});

import AppLayout from "@/app/(app)/_layout";
import AuthLayout from "@/app/(auth)/_layout";

describe("(app)/_layout.tsx route guard (AC-8.3/AC-5.5)", () => {
  afterEach(() => {
    jest.clearAllMocks();
  });

  it("redirects to /login when unauthenticated", async () => {
    mockUseSession.mockReturnValue({ status: "unauthenticated" });
    await render(<AppLayout />);
    expect(screen.getByTestId("redirect").props.children).toBe("/login");
  });

  it("renders the protected stack when authenticated", async () => {
    mockUseSession.mockReturnValue({ status: "authenticated" });
    await render(<AppLayout />);
    expect(screen.getByTestId("stack")).toBeTruthy();
    expect(screen.queryByTestId("redirect")).toBeNull();
  });
});

describe("(auth)/_layout.tsx route guard (AC-8.3)", () => {
  afterEach(() => {
    jest.clearAllMocks();
  });

  it("redirects to /home when already authenticated", async () => {
    mockUseSession.mockReturnValue({ status: "authenticated" });
    await render(<AuthLayout />);
    expect(screen.getByTestId("redirect").props.children).toBe("/home");
  });

  it("renders the auth stack when unauthenticated", async () => {
    mockUseSession.mockReturnValue({ status: "unauthenticated" });
    await render(<AuthLayout />);
    expect(screen.getByTestId("stack")).toBeTruthy();
    expect(screen.queryByTestId("redirect")).toBeNull();
  });
});
