import React from "react";
import { act, render, screen } from "@testing-library/react-native";

// Force the web resolution of the platform fork regardless of this Jest run's default platform, so
// this file proves the specific Layer-2 row: "embed, web resolution -> the <iframe> is mounted with
// embedUrl verbatim" (AC-1.2, AC-1.3), using the REAL `PlaybackEmbed.web.tsx`, not a fake.
jest.mock("../../components/playlist/PlaybackEmbed", () =>
  jest.requireActual("../../components/playlist/PlaybackEmbed.web"),
);

import { PlaybackPanel } from "@/components/playlist/PlaybackPanel";
import type { PlaylistOutput, ProviderConfigOutput } from "@/lib/playlist";
import { ThemeProvider } from "@/theme";

function renderWithTheme(ui: React.ReactElement) {
  return render(<ThemeProvider>{ui}</ThemeProvider>);
}

function provider(overrides: Partial<ProviderConfigOutput> = {}): ProviderConfigOutput {
  return {
    "@id": "/api/config/providers/fixture-one",
    "@type": "ProviderConfig",
    key: "fixture-one",
    displayName: "Fixture One",
    enabled: true,
    playbackMode: "embed",
    isDefault: true,
    ...overrides,
  };
}

function fixturePlaylist(overrides: Partial<PlaylistOutput> = {}): PlaylistOutput {
  return {
    "@id": "/api/playlists/1",
    "@type": "Playlist",
    id: 1,
    concertId: 1,
    provider: "fixture-one",
    name: "Fixture Playlist",
    externalUrl: "https://fixture.invalid/deeplink/1",
    embedUrl: "https://fixture.invalid/embed/1",
    matchRate: 1,
    tracks: [],
    report: [],
    sourceSetlists: [],
    ...overrides,
  };
}

// AC-1.6/AC-7.2: two fixtures, never a real adapter — proves this holds for a second provider too.
describe.each([
  ["fixture-one", "Fixture One"],
  ["fixture-two", "Fixture Two"],
])("PlaybackPanel, web resolution, provider fixture %s (AC-1.2, AC-1.3, D-216)", (key, displayName) => {
  it("mounts the real <iframe> with embedUrl verbatim", async () => {
    await renderWithTheme(
      <PlaybackPanel
        testID="playback"
        playlist={fixturePlaylist({ provider: key, embedUrl: `https://fixture.invalid/embed/${key}` })}
        providers={[provider({ key, displayName })]}
      />,
    );
    const iframe = screen.getByTestId("playback-embed-iframe");
    expect(iframe.props.src).toBe(`https://fixture.invalid/embed/${key}`);
  });
});

describe("PlaybackPanel, web resolution — a genuinely blocked frame (AC-5.2, AC-5.5)", () => {
  it("onError falls back to the deep-link presentation with no error colour/word", async () => {
    await renderWithTheme(
      <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={[provider()]} />,
    );
    const iframe = screen.getByTestId("playback-embed-iframe");

    await act(async () => {
      iframe.props.onError();
    });

    expect(screen.queryByTestId("playback-embed-iframe")).toBeNull();
    expect(screen.getByRole("button", { name: "Open in Fixture One" })).toBeTruthy();
  });
});
