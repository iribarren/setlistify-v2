import React from "react";
import { fireEvent, render, screen } from "@testing-library/react-native";

import { VersionPicker } from "@/components/playlist";
import type { PendingChoicesOutput } from "@/lib/playlist";
import { ThemeProvider } from "@/theme";

function renderWithTheme(ui: React.ReactElement) {
  return render(<ThemeProvider>{ui}</ThemeProvider>);
}

function pendingChoices(overrides: Partial<PendingChoicesOutput> = {}): PendingChoicesOutput {
  return {
    jobId: 1,
    expiresAt: "2026-08-28T00:00:00+00:00",
    songsTotal: 25,
    autoResolvedCount: 22,
    choicesRequiredCount: 3,
    autoResolved: [
      { sourcePosition: 0, bandName: "Headliner", sourceTitle: "Opener Song", providerTrackId: "t-0", label: "top_pick", reasonCode: null, reasonParams: null },
    ],
    decisions: [
      {
        sourcePosition: 5,
        segmentIndex: null,
        bandName: "Headliner",
        sourceTitle: "Ambiguous Song",
        reasonCode: "LOW_CONFIDENCE_MATCH",
        reasonParams: null,
        candidates: [
          { providerTrackId: "c-1", title: "Ambiguous Song (Studio)", artistName: "Headliner", albumName: "Album", releaseYear: 2001, durationMs: 215000, label: "top_pick" },
          { providerTrackId: "c-2", title: "Ambiguous Song (Live)", artistName: "Headliner", albumName: "Live Album", releaseYear: 2005, durationMs: 240000, label: "alternative" },
        ],
      },
    ],
    ...overrides,
  } as unknown as PendingChoicesOutput;
}

// AC-2.5/D-204: no raw confidence number, digit-plus-percent, or star glyph anywhere in the tree.
const CONFIDENCE_LEAKS = [/%\s*$/, /\d+\s*%/, /★|☆|⭐/, /\bconfidence\b/i, /\.\d{2}\b/];

describe("VersionPicker (US-2)", () => {
  it("collapses auto-resolved songs into one summary band, never demanding a tap (AC-2.2)", async () => {
    await renderWithTheme(
      <VersionPicker testID="picker" pendingChoices={pendingChoices()} choices={{}} onChoose={jest.fn()} onContinue={jest.fn()} />,
    );
    expect(screen.getByText("1 song matched automatically")).toBeTruthy();
    // Collapsed by default — the auto-resolved row itself isn't in the tree yet.
    expect(screen.queryByText("Opener Song")).toBeNull();
  });

  it("expanding the auto-resolved summary reveals it, read-only", async () => {
    await renderWithTheme(
      <VersionPicker testID="picker" pendingChoices={pendingChoices()} choices={{}} onChoose={jest.fn()} onContinue={jest.fn()} />,
    );
    await fireEvent.press(screen.getByTestId("picker-auto-resolved-toggle"));
    expect(screen.getByText("Opener Song")).toBeTruthy();
  });

  it("pre-selects the top-pick candidate — submitting with zero taps is valid (AC-2.3)", async () => {
    await renderWithTheme(
      <VersionPicker testID="picker" pendingChoices={pendingChoices()} choices={{}} onChoose={jest.fn()} onContinue={jest.fn()} />,
    );
    const topPick = screen.getByTestId("picker-decision-5-candidate-c-1");
    expect(topPick.props.accessibilityState.checked).toBe(true);
  });

  it("an explicit draft choice overrides the default", async () => {
    await renderWithTheme(
      <VersionPicker testID="picker" pendingChoices={pendingChoices()} choices={{ 5: "c-2" }} onChoose={jest.fn()} onContinue={jest.fn()} />,
    );
    expect(screen.getByTestId("picker-decision-5-candidate-c-2").props.accessibilityState.checked).toBe(true);
    expect(screen.getByTestId("picker-decision-5-candidate-c-1").props.accessibilityState.checked).toBe(false);
  });

  it("'None of these' declines the song (AC-2.6)", async () => {
    const onChoose = jest.fn();
    await renderWithTheme(
      <VersionPicker testID="picker" pendingChoices={pendingChoices()} choices={{}} onChoose={onChoose} onContinue={jest.fn()} />,
    );
    await fireEvent.press(screen.getByTestId("picker-decision-5-none"));
    expect(onChoose).toHaveBeenCalledWith(5, null);
  });

  it("Continue is purely client-side navigation (AC-6.1) — calls onContinue with no request", async () => {
    const onContinue = jest.fn();
    await renderWithTheme(
      <VersionPicker testID="picker" pendingChoices={pendingChoices()} choices={{}} onChoose={jest.fn()} onContinue={onContinue} />,
    );
    await fireEvent.press(screen.getByTestId("picker-continue"));
    expect(onContinue).toHaveBeenCalledTimes(1);
  });

  it("never renders a raw confidence number, percentage or star (AC-2.5)", async () => {
    const view = await renderWithTheme(
      <VersionPicker testID="picker" pendingChoices={pendingChoices()} choices={{}} onChoose={jest.fn()} onContinue={jest.fn()} />,
    );
    await fireEvent.press(screen.getByTestId("picker-auto-resolved-toggle"));
    for (const pattern of CONFIDENCE_LEAKS) {
      expect(view.queryByText(pattern)).toBeNull();
    }
  });
});
