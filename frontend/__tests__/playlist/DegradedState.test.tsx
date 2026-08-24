import React from "react";
import { render, screen } from "@testing-library/react-native";

import { PlaylistDegradedState } from "@/components/playlist";
import type { PlaylistDegradedKind } from "@/components/playlist";
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
    mode: "fast",
    state: "blocked",
    songsTotal: 19,
    songsProcessed: 11,
    matchedCount: 11,
    lowConfidenceCount: 0,
    notFoundCount: 0,
    skippedCount: 0,
    regionRestrictedCount: 0,
    ...overrides,
  };
}

const noop = jest.fn();
const baseProps = {
  providerDisplayName: "Spotify",
  alternativeProvider: null,
  onReconnect: noop,
  onUseAlternative: noop,
  onRetry: noop,
  onCreateAnyway: noop,
  onCheckAgain: noop,
};

const FORBIDDEN_WORDS = [/error/i, /failed/i, /\bproblem\b/i, /\bsorry\b/i];

const BLOCKED_KINDS: PlaylistDegradedKind[] = [
  "blocked_budget",
  "blocked_quota",
  "blocked_reauth",
  "blocked_disabled",
  "blocked_upstream",
];

describe("PlaylistDegradedState (T-6, T-7, D-168/D-170)", () => {
  it.each(BLOCKED_KINDS)("%s carries no error token/icon/word (AC-4.3, T-6)", async (kind) => {
    const view = await renderWithTheme(
      <PlaylistDegradedState testID="degraded" kind={kind} job={job({ blockedReason: "provider_quota" })} {...baseProps} />,
    );
    expect(view.queryByTestId("degraded-error-icon")).toBeNull();
    for (const word of FORBIDDEN_WORDS) {
      expect(view.queryByText(word)).toBeNull();
    }
    view.unmount();
  });

  it("blocked_budget shows a countdown, no retry button", async () => {
    const resumableAfter = new Date(Date.now() + 6 * 60 * 60 * 1000).toISOString();
    await renderWithTheme(
      <PlaylistDegradedState testID="degraded" kind="blocked_budget" job={job({ blockedReason: "setlistfm_budget", resumableAfter })} {...baseProps} />,
    );
    expect(screen.getByTestId("degraded-countdown")).toBeTruthy();
    expect(screen.queryByRole("button", { name: /try again/i })).toBeNull();
  });

  it("blocked_quota shows matched-so-far as saved, no retry button", async () => {
    await renderWithTheme(
      <PlaylistDegradedState testID="degraded" kind="blocked_quota" job={job({ blockedReason: "provider_quota" })} {...baseProps} />,
    );
    expect(screen.getByTestId("degraded-saved-count")).toBeTruthy();
    expect(screen.getByText("11 / 19 saved")).toBeTruthy();
    expect(screen.queryByRole("button", { name: /try again/i })).toBeNull();
  });

  it("blocked_reauth shows the familiar 'Needs reconnect' badge and a Reconnect action", async () => {
    await renderWithTheme(
      <PlaylistDegradedState testID="degraded" kind="blocked_reauth" job={job({ blockedReason: "needs_reauth" })} {...baseProps} />,
    );
    expect(screen.getByText("Needs reconnect")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Reconnect Spotify" })).toBeTruthy();
  });

  it("blocked_disabled offers the live alternative-provider row when one exists (D-175)", async () => {
    await renderWithTheme(
      <PlaylistDegradedState
        testID="degraded"
        kind="blocked_disabled"
        job={job({ blockedReason: "provider_disabled" })}
        {...baseProps}
        alternativeProvider={{ key: "youtube", displayName: "YouTube", isDefault: false }}
      />,
    );
    expect(screen.getByRole("button", { name: "Use YouTube instead" })).toBeTruthy();
  });

  it("degraded_no_songs shows the 'known on setlist.fm' badge and a real 'Check again' retry", async () => {
    await renderWithTheme(
      <PlaylistDegradedState testID="degraded" kind="degraded_no_songs" job={job({ state: "completed", resultKind: "no_source_material" })} {...baseProps} />,
    );
    expect(screen.getByTestId("degraded-known-badge")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Check again" })).toBeTruthy();
  });

  it("degraded_band_unknown offers no misleading retry", async () => {
    await renderWithTheme(
      <PlaylistDegradedState testID="degraded" kind="degraded_band_unknown" job={job({ state: "completed", resultKind: "no_source_material" })} {...baseProps} />,
    );
    expect(screen.queryByRole("button", { name: "Check again" })).toBeNull();
  });

  it.each(["degraded_band_unknown", "degraded_no_songs"] as PlaylistDegradedKind[])(
    "%s carries no error token/icon/word (T-11, AC-4.3, extends spec 16's T-1)",
    async (kind) => {
      const view = await renderWithTheme(
        <PlaylistDegradedState testID="degraded" kind={kind} job={job({ state: "completed", resultKind: "no_source_material" })} {...baseProps} />,
      );
      expect(view.queryByTestId("degraded-error-icon")).toBeNull();
      for (const word of FORBIDDEN_WORDS) {
        expect(view.queryByText(word)).toBeNull();
      }
      view.unmount();
    },
  );

  it("failed_generic is the one variant allowed to use ErrorState, with Retry (T-12)", async () => {
    await renderWithTheme(
      <PlaylistDegradedState testID="degraded" kind="failed_generic" job={job({ state: "failed", failureReason: "unknown_provider" })} {...baseProps} />,
    );
    expect(screen.getByRole("alert")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Try again" })).toBeTruthy();
  });

  it("failed_indeterminate offers 'Create it anyway' only (T-13)", async () => {
    await renderWithTheme(
      <PlaylistDegradedState testID="degraded" kind="failed_indeterminate" job={job({ state: "failed", failureReason: "creation_indeterminate" })} {...baseProps} />,
    );
    expect(screen.getByRole("button", { name: "Create it anyway" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Try again" })).toBeNull();
  });
});
