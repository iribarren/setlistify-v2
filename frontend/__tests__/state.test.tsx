import React from "react";
import { render, screen } from "@testing-library/react-native";

import { DegradedState, EmptyState, ErrorState, LoadingState } from "@/components/state";
import { ThemeProvider } from "@/theme";

// @testing-library/react-native's `render()` is async (it wraps the initial commit in `act()`).
function renderWithTheme(ui: React.ReactElement) {
  return render(<ThemeProvider>{ui}</ThemeProvider>);
}

describe("state components", () => {
  it("LoadingState announces itself to assistive technology (AC-5.5)", async () => {
    await renderWithTheme(<LoadingState title="Pulling the setlist" body="Checking setlist.fm…" />);
    const node = screen.getByLabelText("Pulling the setlist");
    expect(node.props.accessibilityLiveRegion).toBe("polite");
    expect(screen.getByText("Checking setlist.fm…")).toBeTruthy();
  });

  it("EmptyState renders an optional action", async () => {
    const onPress = jest.fn();
    await renderWithTheme(
      <EmptyState
        title="No concerts yet"
        body="Track the first show you're going to."
        action={{ label: "Track a concert", onPress }}
      />,
    );
    expect(screen.getByText("No concerts yet")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Track a concert" })).toBeTruthy();
  });

  it("ErrorState always renders a retry action (AC-5.4)", async () => {
    const onPress = jest.fn();
    await renderWithTheme(
      <ErrorState
        title="Couldn't reach setlist.fm"
        body="Try again shortly."
        action={{ label: "Try again", onPress }}
      />,
    );
    expect(screen.getByRole("alert")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Try again" })).toBeTruthy();
  });

  it("DegradedState is visually and structurally distinct from ErrorState (AC-5.3)", async () => {
    await renderWithTheme(
      <DegradedState
        testID="degraded"
        title="Playlist ready — 5 songs need a pick"
        body="Everything else matched automatically."
        progress={{ completed: 14, total: 19 }}
        action={{ label: "Review unmatched songs", onPress: jest.fn() }}
      />,
    );

    // The progress fraction is present and correct — DegradedState's defining visual feature.
    expect(screen.getByText("14 / 19")).toBeTruthy();

    // No alert role — that vocabulary belongs to ErrorState alone.
    expect(screen.queryByRole("alert")).toBeNull();

    // A progressbar is present with the right value, unlike Error/Empty/Loading.
    const bar = screen.getByRole("progressbar");
    expect(bar.props.accessibilityValue).toEqual({ min: 0, max: 19, now: 14 });
  });

  it("DegradedState and ErrorState never share their alert-role vocabulary", async () => {
    const { unmount } = await renderWithTheme(
      <ErrorState
        title="Couldn't reach setlist.fm"
        body="Try again."
        action={{ label: "Try again", onPress: jest.fn() }}
      />,
    );
    const errorAlertCount = screen.queryAllByRole("alert").length;
    await unmount();

    await renderWithTheme(
      <DegradedState title="Playlist ready" body="Everything else matched." progress={{ completed: 1, total: 2 }} />,
    );
    const degradedAlertCount = screen.queryAllByRole("alert").length;

    expect(errorAlertCount).toBeGreaterThan(0);
    expect(degradedAlertCount).toBe(0);
  });
});
