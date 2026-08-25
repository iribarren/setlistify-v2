import React from "react";
import { fireEvent, render, screen } from "@testing-library/react-native";

import { ResumeBanner } from "@/components/playlist";
import type { PlaylistGenerationJobOutput } from "@/lib/playlist";
import { ThemeProvider } from "@/theme";

function renderWithTheme(ui: React.ReactElement) {
  return render(<ThemeProvider>{ui}</ThemeProvider>);
}

function job(overrides: Partial<PlaylistGenerationJobOutput> = {}): PlaylistGenerationJobOutput {
  return {
    "@id": "/api/playlist-generation-jobs/1",
    "@type": "PlaylistGenerationJob",
    id: 1,
    concertId: 1,
    provider: "spotify",
    mode: "normal",
    state: "awaiting_version_choice",
    songsTotal: 25,
    songsProcessed: 25,
    matchedCount: 0,
    lowConfidenceCount: 0,
    notFoundCount: 0,
    skippedCount: 0,
    regionRestrictedCount: 0,
    ...overrides,
  } as unknown as PlaylistGenerationJobOutput;
}

const FORBIDDEN_WORDS = [/error/i, /failed/i, /\bproblem\b/i, /\bsorry\b/i];

describe("ResumeBanner (US-3/D-207)", () => {
  it("leads with what's done for a version-choice suspension (AC-3.3)", async () => {
    await renderWithTheme(
      <ResumeBanner
        testID="resume"
        job={job({ choicesRequiredCount: 3, choicesMadeCount: 2 } as unknown as Partial<PlaylistGenerationJobOutput>)}
        onResume={jest.fn()}
        onStartOver={jest.fn()}
        startingOver={false}
      />,
    );
    expect(screen.getByText("2 of 3 songs are already decided")).toBeTruthy();
  });

  it("uses info-family styling, never an error/warning token or forbidden word (D-168)", async () => {
    const view = await renderWithTheme(
      <ResumeBanner testID="resume" job={job()} onResume={jest.fn()} onStartOver={jest.fn()} startingOver={false} />,
    );
    for (const word of FORBIDDEN_WORDS) {
      expect(view.queryByText(word)).toBeNull();
    }
  });

  it("Resume calls onResume directly, no confirmation needed", async () => {
    const onResume = jest.fn();
    await renderWithTheme(<ResumeBanner testID="resume" job={job()} onResume={onResume} onStartOver={jest.fn()} startingOver={false} />);
    await fireEvent.press(screen.getByTestId("resume-resume"));
    expect(onResume).toHaveBeenCalledTimes(1);
  });

  it("'Start over' requires an inline confirmation naming what's discarded before firing (D-208)", async () => {
    const onStartOver = jest.fn();
    await renderWithTheme(
      <ResumeBanner
        testID="resume"
        job={job({ choicesRequiredCount: 3, choicesMadeCount: 2 } as unknown as Partial<PlaylistGenerationJobOutput>)}
        onResume={jest.fn()}
        onStartOver={onStartOver}
        startingOver={false}
      />,
    );
    await fireEvent.press(screen.getByTestId("resume-start-over"));
    expect(onStartOver).not.toHaveBeenCalled();
    expect(screen.getByText(/discards/i)).toBeTruthy();
    expect(screen.getByText(/2 of 3 songs you've already decided/)).toBeTruthy();

    await fireEvent.press(screen.getByTestId("resume-start-over-confirm"));
    expect(onStartOver).toHaveBeenCalledTimes(1);
  });

  it("'Keep my progress' backs out of the confirmation without calling onStartOver", async () => {
    const onStartOver = jest.fn();
    await renderWithTheme(<ResumeBanner testID="resume" job={job()} onResume={jest.fn()} onStartOver={onStartOver} startingOver={false} />);
    await fireEvent.press(screen.getByTestId("resume-start-over"));
    await fireEvent.press(screen.getByTestId("resume-start-over-cancel"));
    expect(screen.queryByTestId("resume-start-over-confirm")).toBeNull();
    expect(onStartOver).not.toHaveBeenCalled();
  });
});
