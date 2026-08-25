import React from "react";
import { fireEvent, render, screen } from "@testing-library/react-native";

import { SetlistPicker } from "@/components/playlist";
import type { CandidateSetlistsOutput } from "@/lib/playlist";
import { ThemeProvider } from "@/theme";

function renderWithTheme(ui: React.ReactElement) {
  return render(<ThemeProvider>{ui}</ThemeProvider>);
}

function candidateSetlists(overrides: Partial<CandidateSetlistsOutput> = {}): CandidateSetlistsOutput {
  return {
    jobId: 1,
    expiresAt: "2026-09-01T00:00:00+00:00",
    concertId: 1,
    bands: [
      {
        bandId: 10,
        bandName: "Headliner",
        billingOrder: 0,
        recommendedSetlistfmId: "rec-1",
        recommendedReason: "Most recent",
        noSetlistCause: null,
        candidates: [
          {
            setlistfmId: "same-night",
            eventDate: "25-08-2026",
            venueName: "The Venue",
            cityName: "Bilbao",
            countryCode: "ES",
            tourName: null,
            songCount: 18,
            isSameNight: true,
            url: "https://www.setlist.fm/setlist/headliner/2026/the-venue-same-night.html",
          },
          {
            setlistfmId: "rec-1",
            eventDate: "10-08-2026",
            venueName: "Other Venue",
            cityName: "Madrid",
            countryCode: "ES",
            tourName: null,
            songCount: 16,
            isSameNight: false,
            url: null,
          },
        ],
      },
    ],
    ...overrides,
  } as unknown as CandidateSetlistsOutput;
}

describe("SetlistPicker (US-1)", () => {
  it("pre-selects the 'Same night' candidate when one matches (AC-1.4)", async () => {
    await renderWithTheme(
      <SetlistPicker
        testID="picker"
        candidateSetlists={candidateSetlists()}
        choices={{}}
        onChoose={jest.fn()}
        onSubmit={jest.fn()}
        submitting={false}
      />,
    );
    expect(screen.getByText("Same night")).toBeTruthy();
    const sameNightRow = screen.getByTestId("picker-band-10-candidate-same-night");
    expect(sameNightRow.props.accessibilityState.checked).toBe(true);
  });

  it("falls back to the recommended candidate with its reason when no night matches", async () => {
    const fixture = candidateSetlists();
    (fixture.bands![0]!.candidates![0] as { isSameNight: boolean }).isSameNight = false;
    await renderWithTheme(
      <SetlistPicker testID="picker" candidateSetlists={fixture} choices={{}} onChoose={jest.fn()} onSubmit={jest.fn()} submitting={false} />,
    );
    expect(screen.getByText("Most recent")).toBeTruthy();
    const recommendedRow = screen.getByTestId("picker-band-10-candidate-rec-1");
    expect(recommendedRow.props.accessibilityState.checked).toBe(true);
  });

  it("submits the effective selection (default, unchanged) on Continue", async () => {
    const onSubmit = jest.fn();
    await renderWithTheme(
      <SetlistPicker testID="picker" candidateSetlists={candidateSetlists()} choices={{}} onChoose={jest.fn()} onSubmit={onSubmit} submitting={false} />,
    );
    await fireEvent.press(screen.getByTestId("picker-submit"));
    expect(onSubmit).toHaveBeenCalledWith([{ bandId: 10, setlistfmId: "same-night" }]);
  });

  it("a band with noSetlistCause renders as an explanatory row, not a question (AC-1.8)", async () => {
    const fixture = candidateSetlists({
      bands: [
        {
          bandId: 20,
          bandName: "Support Act",
          billingOrder: 1,
          recommendedSetlistfmId: null,
          recommendedReason: null,
          noSetlistCause: "band_unknown",
          candidates: [],
        },
      ],
    } as unknown as Partial<CandidateSetlistsOutput>);
    await renderWithTheme(
      <SetlistPicker testID="picker" candidateSetlists={fixture} choices={{}} onChoose={jest.fn()} onSubmit={jest.fn()} submitting={false} />,
    );
    expect(screen.getByTestId("picker-band-20-unavailable")).toBeTruthy();
    expect(screen.queryByRole("radio")).toBeNull();
  });

  it("blocks submit until every qualifying band is answered (AC-1.7, multi-band)", async () => {
    const fixture = candidateSetlists({
      bands: [
        candidateSetlists().bands![0]!,
        {
          bandId: 30,
          bandName: "Second Band",
          billingOrder: 1,
          recommendedSetlistfmId: null,
          recommendedReason: null,
          noSetlistCause: null,
          candidates: [
            {
              setlistfmId: "second-1",
              eventDate: "01-08-2026",
              venueName: "Somewhere",
              cityName: "Bilbao",
              countryCode: "ES",
              tourName: null,
              songCount: 10,
              isSameNight: false,
              url: null,
            },
          ],
        },
      ],
    } as unknown as Partial<CandidateSetlistsOutput>);
    const onSubmit = jest.fn();
    const onChoose = jest.fn();
    await renderWithTheme(
      <SetlistPicker testID="picker" candidateSetlists={fixture} choices={{}} onChoose={onChoose} onSubmit={onSubmit} submitting={false} />,
    );
    // Band 10 has a default (same night); band 30 has no recommendation and no same-night match —
    // unanswered until the user picks. Submit stays disabled.
    expect(screen.getByTestId("picker-submit").props.accessibilityState.disabled).toBe(true);

    await fireEvent.press(screen.getByTestId("picker-band-30-candidate-second-1"));
    expect(onChoose).toHaveBeenCalledWith(30, "second-1");
  });
});
