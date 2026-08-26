import React from "react";
import { fireEvent, render, screen } from "@testing-library/react-native";

import { ReviewPromptCard } from "@/components/review";
import type { CachedConcert } from "@/lib/concerts";
import { ThemeProvider } from "@/theme";

function concert(): CachedConcert {
  return {
    id: 1,
    date: "2026-08-20",
    timezone: "Europe/Madrid",
    status: "past",
    lineup: [{ band: { id: 1, name: "Iceage" }, billingOrder: 0 }],
    venue: {},
    reviewSummary: null,
    createdAt: "2026-01-01T00:00:00+00:00",
    updatedAt: "2026-01-01T00:00:00+00:00",
  } as CachedConcert;
}

describe("ReviewPromptCard (US-7)", () => {
  it("names the band and offers write/dismiss actions", async () => {
    const onPress = jest.fn();
    const onDismiss = jest.fn();
    await render(
      <ThemeProvider>
        <ReviewPromptCard testID="review-prompt-card" concert={concert()} onPress={onPress} onDismiss={onDismiss} />
      </ThemeProvider>,
    );

    expect(screen.getByText("How was Iceage?")).toBeTruthy();

    await fireEvent.press(screen.getByTestId("review-prompt-card-write"));
    expect(onPress).toHaveBeenCalledTimes(1);

    await fireEvent.press(screen.getByTestId("review-prompt-card-dismiss"));
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });
});
