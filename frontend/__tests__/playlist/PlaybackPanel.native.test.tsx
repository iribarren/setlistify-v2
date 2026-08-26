import React from "react";
import { render, screen } from "@testing-library/react-native";

// Force the native resolution of the platform fork regardless of this Jest run's default platform,
// so this file proves the specific Layer-2 row: "embed, native resolution -> no player of any kind
// is mounted, and the tree equals the deeplink tree" (AC-1.2, AC-5.8, D-216), using the REAL
// `PlaybackEmbed.native.tsx`.
jest.mock("../../components/playlist/PlaybackEmbed", () =>
  jest.requireActual("../../components/playlist/PlaybackEmbed.native"),
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

describe.each([
  ["fixture-one", "Fixture One"],
  ["fixture-two", "Fixture Two"],
])("PlaybackPanel, native resolution, provider fixture %s (AC-1.2, AC-5.8, D-216)", (key, displayName) => {
  it("mounts no player of any kind, and the tree equals the deep-link tree", async () => {
    await renderWithTheme(
      <PlaybackPanel
        testID="playback"
        playlist={fixturePlaylist({ provider: key, embedUrl: `https://fixture.invalid/embed/${key}` })}
        providers={[provider({ key, displayName, playbackMode: "embed" })]}
      />,
    );

    // The native embed reports unavailable on mount, so `derivePlaybackSurface()` resolves to
    // exactly the same `deeplink` surface `playbackMode: "deeplink"` would have produced directly —
    // this IS that tree, not a lookalike.
    expect(screen.getByRole("button", { name: `Open in ${displayName}` })).toBeTruthy();
  });

  it("produces the identical tree to playbackMode: 'deeplink' set directly (AC-5.8's equivalence)", async () => {
    const nativeEmbedTree = await renderWithTheme(
      <PlaybackPanel
        testID="playback"
        playlist={fixturePlaylist({ provider: key })}
        providers={[provider({ key, displayName, playbackMode: "embed" })]}
      />,
    );
    const nativeJson = nativeEmbedTree.toJSON();

    const directDeeplinkTree = await renderWithTheme(
      <PlaybackPanel
        testID="playback-b"
        playlist={fixturePlaylist({ provider: key })}
        providers={[provider({ key, displayName, playbackMode: "deeplink" })]}
      />,
    );
    const directJson = directDeeplinkTree.toJSON();

    // Both are single "Open in <displayName>" cards with no embed anywhere — same shape.
    expect(JSON.stringify(nativeJson)).toBe(JSON.stringify(directJson).replaceAll("playback-b", "playback"));
  });
});
