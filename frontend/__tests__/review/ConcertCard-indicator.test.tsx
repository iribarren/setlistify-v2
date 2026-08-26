import React from "react";
import { render, screen } from "@testing-library/react-native";

import { ConcertCard } from "@/components/concert";
import type { CachedConcert } from "@/lib/concerts";
import { ThemeProvider } from "@/theme";

function concert(overrides: Partial<CachedConcert> = {}): CachedConcert {
  return {
    id: 1,
    date: "2026-01-01",
    timezone: "Europe/Madrid",
    status: "past",
    lineup: [{ band: { id: 1, name: "Iceage" }, billingOrder: 0 }],
    venue: {},
    reviewSummary: null,
    createdAt: "2026-01-01T00:00:00+00:00",
    updatedAt: "2026-01-01T00:00:00+00:00",
    ...overrides,
  } as CachedConcert;
}

async function renderCard(concertValue: CachedConcert) {
  return render(
    <ThemeProvider>
      <ConcertCard testID="concert-card" concert={concertValue} />
    </ThemeProvider>,
  );
}

describe("ConcertCard review indicator (AC-6.3, AC-6.4)", () => {
  it("AC-6.3: an unreviewed past concert renders no indicator at all — absence is the signal", async () => {
    await renderCard(concert());
    expect(screen.queryByTestId("concert-card-review-indicator")).toBeNull();
  });

  it("AC-6.3: a past concert with a rated review shows the star rating", async () => {
    await renderCard(concert({ reviewSummary: { rating: 4, highlightTitle: null, updatedAt: "2026-01-02T00:00:00+00:00" } }));
    expect(screen.getByTestId("concert-card-review-indicator")).toBeTruthy();
  });

  it("AC-6.3: a past concert with a ratingless review shows a neutral 'Written up' badge", async () => {
    await renderCard(
      concert({ reviewSummary: { rating: null, highlightTitle: "Encore", updatedAt: "2026-01-02T00:00:00+00:00" } }),
    );
    expect(screen.getByText("Written up")).toBeTruthy();
  });

  it("AC-6.4: an upcoming concert never shows a review indicator, even with a reviewSummary present", async () => {
    await renderCard(
      concert({
        status: "upcoming",
        reviewSummary: { rating: 5, highlightTitle: null, updatedAt: "2026-01-02T00:00:00+00:00" },
      }),
    );
    expect(screen.queryByTestId("concert-card-review-indicator")).toBeNull();
  });
});
