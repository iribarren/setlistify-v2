import React from "react";
import { fireEvent, render, screen } from "@testing-library/react-native";

import { ConfirmSummary } from "@/components/playlist";
import type { PendingChoicesOutput } from "@/lib/playlist";
import { ThemeProvider } from "@/theme";

function renderWithTheme(ui: React.ReactElement) {
  return render(<ThemeProvider>{ui}</ThemeProvider>);
}

function pendingChoices(): PendingChoicesOutput {
  return {
    jobId: 1,
    expiresAt: "2026-08-28T00:00:00+00:00",
    songsTotal: 25,
    autoResolvedCount: 22,
    choicesRequiredCount: 3,
    autoResolved: [],
    decisions: [
      {
        sourcePosition: 5,
        segmentIndex: null,
        bandName: "Headliner",
        sourceTitle: "Ambiguous Song",
        reasonCode: null,
        reasonParams: null,
        candidates: [
          { providerTrackId: "c-1", title: "Ambiguous Song (Studio)", artistName: "Headliner", albumName: null, releaseYear: null, durationMs: null, label: "top_pick" },
        ],
      },
      {
        sourcePosition: 6,
        segmentIndex: null,
        bandName: "Headliner",
        sourceTitle: "Another Song",
        reasonCode: null,
        reasonParams: null,
        candidates: [
          { providerTrackId: "c-2", title: "Another Song (Studio)", artistName: "Headliner", albumName: null, releaseYear: null, durationMs: null, label: "only_match" },
        ],
      },
    ],
  } as unknown as PendingChoicesOutput;
}

describe("ConfirmSummary (D-194)", () => {
  it("computes the count as autoResolvedCount + decisions.length, traceable to the previous screen", async () => {
    await renderWithTheme(
      <ConfirmSummary testID="confirm" pendingChoices={pendingChoices()} choices={{}} onBack={jest.fn()} onBuild={jest.fn()} building={false} />,
    );
    expect(screen.getByText("22 automatic + 2 confirmed = 24 songs")).toBeTruthy();
  });

  it("shows the effective (default) choice per song when the user hasn't overridden it", async () => {
    await renderWithTheme(
      <ConfirmSummary testID="confirm" pendingChoices={pendingChoices()} choices={{}} onBack={jest.fn()} onBuild={jest.fn()} building={false} />,
    );
    expect(screen.getByText("Ambiguous Song (Studio)")).toBeTruthy();
  });

  it("shows 'Skipped' for a declined song", async () => {
    await renderWithTheme(
      <ConfirmSummary testID="confirm" pendingChoices={pendingChoices()} choices={{ 5: null }} onBack={jest.fn()} onBuild={jest.fn()} building={false} />,
    );
    expect(screen.getByText("Skipped — none of these")).toBeTruthy();
  });

  it("Back is client-side only — calls onBack with no request (AC-6.1)", async () => {
    const onBack = jest.fn();
    await renderWithTheme(
      <ConfirmSummary testID="confirm" pendingChoices={pendingChoices()} choices={{}} onBack={onBack} onBuild={jest.fn()} building={false} />,
    );
    await fireEvent.press(screen.getByTestId("confirm-back"));
    expect(onBack).toHaveBeenCalledTimes(1);
  });

  it("'Build the playlist' calls onBuild — this literally IS the version-choices submission (D-194)", async () => {
    const onBuild = jest.fn();
    await renderWithTheme(
      <ConfirmSummary testID="confirm" pendingChoices={pendingChoices()} choices={{}} onBack={jest.fn()} onBuild={onBuild} building={false} />,
    );
    await fireEvent.press(screen.getByTestId("confirm-build"));
    expect(onBuild).toHaveBeenCalledTimes(1);
  });
});
