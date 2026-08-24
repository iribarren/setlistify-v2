import React from "react";
import { render, screen } from "@testing-library/react-native";

import { ReportList } from "@/components/playlist";
import type { PlaylistTrackOutput } from "@/lib/playlist";
import { ThemeProvider } from "@/theme";

function renderWithTheme(ui: React.ReactElement) {
  return render(<ThemeProvider>{ui}</ThemeProvider>);
}

function tracksFixture(): PlaylistTrackOutput[] {
  const matched = Array.from({ length: 22 }, (_, index) => ({
    ordinal: index,
    sourcePosition: index,
    sourceTitle: `Matched song ${index}`,
    outcome: "matched",
  }));
  const gaps: PlaylistTrackOutput[] = [
    { ordinal: 22, sourcePosition: 22, sourceTitle: "Talk Show Host", outcome: "matched_low_confidence", reasonCode: "LOW_CONFIDENCE_MATCH" },
    { ordinal: 23, sourcePosition: 23, sourceTitle: "Follow Me Around", outcome: "not_found", reasonCode: "TRACK_NOT_IN_CATALOG" },
    { ordinal: 24, sourcePosition: 24, sourceTitle: "True Love Waits", outcome: "not_found", reasonCode: "LIVE_VERSION_ONLY" },
  ];
  return [...matched, ...gaps];
}

describe("ReportList (T-8, AC-5.1)", () => {
  it("a 25-song setlist with 3 gaps renders exactly 3 rows — matched songs never appear", async () => {
    await renderWithTheme(<ReportList testID="report" summary={[]} tracks={tracksFixture()} />);
    expect(screen.getByText("Talk Show Host")).toBeTruthy();
    expect(screen.getByText("Follow Me Around")).toBeTruthy();
    expect(screen.getByText("True Love Waits")).toBeTruthy();
    expect(screen.queryByText("Matched song 0")).toBeNull();
    expect(screen.getByTestId("report-row-22")).toBeTruthy();
    expect(screen.getByTestId("report-row-23")).toBeTruthy();
    expect(screen.getByTestId("report-row-24")).toBeTruthy();
    expect(screen.queryByTestId("report-row-0")).toBeNull();
  });

  it("renders job-level summary codes as a note above the list (AC-5.5)", async () => {
    await renderWithTheme(
      <ReportList
        testID="report"
        summary={[{ code: "BANDS_OMITTED_FOR_LENGTH", params: { bands: "The Openers" } }]}
        tracks={[]}
      />,
    );
    expect(screen.getByText(/The Openers/)).toBeTruthy();
  });

  it("never renders a raw code or enum value (AC-5.3)", async () => {
    await renderWithTheme(
      <ReportList
        testID="report"
        summary={[]}
        tracks={[{ ordinal: 0, sourcePosition: 0, sourceTitle: "Creep", outcome: "not_found", reasonCode: "TRACK_NOT_IN_CATALOG" }]}
      />,
    );
    expect(screen.queryByText("TRACK_NOT_IN_CATALOG")).toBeNull();
    expect(screen.queryByText("not_found")).toBeNull();
  });
});
