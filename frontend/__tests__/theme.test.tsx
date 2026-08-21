import React from "react";
import { render, screen } from "@testing-library/react-native";
import { Text } from "react-native";
import * as ReactNative from "react-native";

import { darkColors, lightColors, ThemeProvider, useTheme } from "@/theme";

function Probe(): React.JSX.Element {
  const { colors, scheme } = useTheme();
  return (
    <Text testID="probe" accessibilityLabel={scheme}>
      {colors["text-primary"]}
    </Text>
  );
}

describe("theme", () => {
  afterEach(() => {
    jest.restoreAllMocks();
  });

  it("resolves the light palette by default in tests (AC-2.2)", async () => {
    jest.spyOn(ReactNative, "useColorScheme").mockReturnValue("light");
    await render(
      <ThemeProvider>
        <Probe />
      </ThemeProvider>,
    );
    expect(screen.getByTestId("probe").props.children).toBe(lightColors["text-primary"]);
  });

  // AC-10.4: a component resolves dark-mode tokens when the OS color scheme is dark.
  it("resolves the dark palette when the OS color scheme is dark (AC-3.1)", async () => {
    jest.spyOn(ReactNative, "useColorScheme").mockReturnValue("dark");
    await render(
      <ThemeProvider>
        <Probe />
      </ThemeProvider>,
    );
    const probe = screen.getByTestId("probe");
    expect(probe.props.children).toBe(darkColors["text-primary"]);
    // AC-3.3: the dark `bg` is the deliberate warm near-black, never pure #000.
    expect(darkColors["bg"]).toBe("#0f0a08");
    expect(darkColors["bg"]).not.toBe("#000000");
  });
});
