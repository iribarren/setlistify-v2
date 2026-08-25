import React from "react";
import { fireEvent, render, screen } from "@testing-library/react-native";

import { ModeSheet } from "@/components/playlist";
import { ThemeProvider } from "@/theme";

function renderWithTheme(ui: React.ReactElement) {
  return render(<ThemeProvider>{ui}</ThemeProvider>);
}

describe("ModeSheet (D-203)", () => {
  it("never mentions 'Fast mode' or 'Normal mode' — those are internal names, not user copy", async () => {
    const view = await renderWithTheme(
      <ModeSheet testID="sheet" generating={false} onSelectFast={jest.fn()} onSelectChooseYourself={jest.fn()} onDismiss={jest.fn()} />,
    );
    expect(view.queryByText(/fast mode/i)).toBeNull();
    expect(view.queryByText(/normal mode/i)).toBeNull();
  });

  it("selecting the Fast card calls onSelectFast", async () => {
    const onSelectFast = jest.fn();
    await renderWithTheme(
      <ModeSheet testID="sheet" generating={false} onSelectFast={onSelectFast} onSelectChooseYourself={jest.fn()} onDismiss={jest.fn()} />,
    );
    await fireEvent.press(screen.getByTestId("sheet-fast"));
    expect(onSelectFast).toHaveBeenCalledTimes(1);
  });

  it("selecting 'Choose it yourself' calls onSelectChooseYourself", async () => {
    const onSelectChooseYourself = jest.fn();
    await renderWithTheme(
      <ModeSheet testID="sheet" generating={false} onSelectFast={jest.fn()} onSelectChooseYourself={onSelectChooseYourself} onDismiss={jest.fn()} />,
    );
    await fireEvent.press(screen.getByTestId("sheet-choose-yourself"));
    expect(onSelectChooseYourself).toHaveBeenCalledTimes(1);
  });

  it("Cancel calls onDismiss", async () => {
    const onDismiss = jest.fn();
    await renderWithTheme(
      <ModeSheet testID="sheet" generating={false} onSelectFast={jest.fn()} onSelectChooseYourself={jest.fn()} onDismiss={onDismiss} />,
    );
    await fireEvent.press(screen.getByTestId("sheet-dismiss"));
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });
});
