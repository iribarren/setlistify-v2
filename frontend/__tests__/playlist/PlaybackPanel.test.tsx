import React from "react";
import { act, render, screen } from "@testing-library/react-native";

// D-216: swap in a controllable fake for the platform-forked embed so this file can drive its
// onLoad/onUnavailable callbacks directly and assert `PlaybackPanel`'s own state machine (the
// fallback timer, its stickiness, and re-render-without-remount) independently of which real
// platform half is under test elsewhere (`PlaybackPanel.web.test.tsx` / `.native.test.tsx`).
const mockOnUnavailableCalls: (() => void)[] = [];
let mockLatestOnLoad: (() => void) | null = null;
let mockMountCount = 0;

jest.mock("../../components/playlist/PlaybackEmbed", () => ({
  PlaybackEmbed: (props: { url: string; onLoad: () => void; onUnavailable: () => void }) => {
    const React_ = require("react");
    mockMountCount += 1;
    mockOnUnavailableCalls.push(props.onUnavailable);
    mockLatestOnLoad = props.onLoad;
    return React_.createElement("MockEmbed", { testID: "mock-embed", url: props.url });
  },
}));

import { PlaybackPanel } from "@/components/playlist/PlaybackPanel";
import { EMBED_LOAD_TIMEOUT_MS, type PlaylistOutput, type ProviderConfigOutput } from "@/lib/playlist";
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

const FORBIDDEN_WORDS = [/error/i, /failed/i, /\bproblem\b/i, /\bsorry\b/i];

beforeEach(() => {
  mockOnUnavailableCalls.length = 0;
  mockLatestOnLoad = null;
  mockMountCount = 0;
});

describe("PlaybackPanel (Layer 2, spec 19)", () => {
  it("embed mode mounts the embed with embedUrl verbatim and a provider-neutral caveat (AC-1.1, AC-1.3, AC-1.4, D-222)", async () => {
    await renderWithTheme(
      <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={[provider()]} />,
    );
    expect(screen.getByTestId("mock-embed").props.url).toBe("https://fixture.invalid/embed/1");
    expect(screen.getByText(/Playback here depends on your Fixture One account/)).toBeTruthy();
    expect(screen.queryByRole("button", { name: /Open in/ })).toBeNull();
  });

  it("deeplink mode renders 'Open in <displayName>' and mounts no embed (AC-2.1)", async () => {
    await renderWithTheme(
      <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={[provider({ playbackMode: "deeplink" })]} />,
    );
    expect(screen.getByRole("button", { name: "Open in Fixture One" })).toBeTruthy();
    expect(screen.queryByTestId("mock-embed")).toBeNull();
  });

  it("off mode renders nothing at all — no embed, no open action, no placeholder (AC-3.1)", async () => {
    const { toJSON } = await renderWithTheme(
      <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={[provider({ playbackMode: "off" })]} />,
    );
    expect(toJSON()).toBeNull();
    expect(screen.queryByTestId("playback")).toBeNull();
  });

  it("a second, unrelated provider fixture does not affect this playlist's panel (AC-4.4)", async () => {
    await renderWithTheme(
      <PlaybackPanel
        testID="playback"
        playlist={fixturePlaylist({ provider: "fixture-one" })}
        providers={[provider({ key: "fixture-one", playbackMode: "embed" }), provider({ key: "fixture-two", playbackMode: "off" })]}
      />,
    );
    expect(screen.getByTestId("mock-embed")).toBeTruthy();
  });

  it("AC-4.1/AC-4.3: re-rendering with a changed ProviderConfigOutput changes the tree without a remount", async () => {
    const { rerender } = await renderWithTheme(
      <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={[provider({ playbackMode: "embed" })]} />,
    );
    expect(screen.getByTestId("mock-embed")).toBeTruthy();

    await act(async () => {
      rerender(
        <ThemeProvider>
          <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={[provider({ playbackMode: "deeplink" })]} />
        </ThemeProvider>,
      );
    });

    expect(screen.queryByTestId("mock-embed")).toBeNull();
    expect(screen.getByRole("button", { name: "Open in Fixture One" })).toBeTruthy();
  });

  it("onUnavailable (an onError-equivalent) falls back to the deep-link presentation with no error colour/word (AC-5.2, AC-5.5)", async () => {
    await renderWithTheme(
      <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={[provider()]} />,
    );
    expect(screen.getByTestId("mock-embed")).toBeTruthy();

    await act(async () => {
      mockOnUnavailableCalls[0]();
    });

    expect(screen.queryByTestId("mock-embed")).toBeNull();
    expect(screen.getByRole("button", { name: "Open in Fixture One" })).toBeTruthy();
    for (const word of FORBIDDEN_WORDS) {
      expect(screen.queryByText(word)).toBeNull();
    }
  });

  it("the 8s watchdog falls back to deep-link when no load event ever fires (AC-5.3, D-215)", async () => {
    jest.useFakeTimers();
    try {
      await renderWithTheme(
        <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={[provider()]} />,
      );
      expect(screen.getByTestId("mock-embed")).toBeTruthy();

      await act(async () => {
        jest.advanceTimersByTime(EMBED_LOAD_TIMEOUT_MS);
      });

      expect(screen.queryByTestId("mock-embed")).toBeNull();
      expect(screen.getByRole("button", { name: "Open in Fixture One" })).toBeTruthy();
    } finally {
      jest.useRealTimers();
    }
  });

  it("a load event clears the watchdog — no fallback after the timeout (AC-5.3's converse)", async () => {
    jest.useFakeTimers();
    try {
      await renderWithTheme(
        <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={[provider()]} />,
      );
      await act(async () => {
        mockLatestOnLoad?.();
      });
      await act(async () => {
        jest.advanceTimersByTime(EMBED_LOAD_TIMEOUT_MS);
      });

      expect(screen.getByTestId("mock-embed")).toBeTruthy();
    } finally {
      jest.useRealTimers();
    }
  });

  it("the fallback is sticky within a mount — a subsequent re-render does not re-mount the embed (AC-5.4)", async () => {
    const { rerender } = await renderWithTheme(
      <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={[provider()]} />,
    );
    await act(async () => {
      mockOnUnavailableCalls[0]();
    });
    expect(screen.queryByTestId("mock-embed")).toBeNull();
    const mountsAfterFallback = mockMountCount;

    // The admin flips it back to embed mid-session — still no embed, because embedUnavailable is
    // sticky for this mount (D-214).
    await act(async () => {
      rerender(
        <ThemeProvider>
          <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={[provider({ playbackMode: "embed" })]} />
        </ThemeProvider>,
      );
    });

    expect(screen.queryByTestId("mock-embed")).toBeNull();
    expect(mockMountCount).toBe(mountsAfterFallback);
  });

  it("a genuinely different playlist resets the sticky fallback", async () => {
    const { rerender } = await renderWithTheme(
      <PlaybackPanel testID="playback" playlist={fixturePlaylist({ id: 1 })} providers={[provider()]} />,
    );
    await act(async () => {
      mockOnUnavailableCalls[0]();
    });
    expect(screen.queryByTestId("mock-embed")).toBeNull();

    await act(async () => {
      rerender(
        <ThemeProvider>
          <PlaybackPanel testID="playback" playlist={fixturePlaylist({ id: 2, embedUrl: "https://fixture.invalid/embed/2" })} providers={[provider()]} />
        </ThemeProvider>,
      );
    });

    expect(screen.getByTestId("mock-embed").props.url).toBe("https://fixture.invalid/embed/2");
  });

  it("while the provider config is loading (provider undefined) — metadata only, deny by default (AC-4.5)", async () => {
    const { toJSON } = await renderWithTheme(
      <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={undefined} />,
    );
    expect(toJSON()).toBeNull();
  });

  it("a disabled provider in embed mode degrades to deeplink, never off (AC-5.7, D-219)", async () => {
    await renderWithTheme(
      <PlaybackPanel testID="playback" playlist={fixturePlaylist()} providers={[provider({ enabled: false })]} />,
    );
    expect(screen.getByRole("button", { name: "Open in Fixture One" })).toBeTruthy();
    expect(screen.queryByTestId("mock-embed")).toBeNull();
  });

  it("a null embedUrl in embed mode degrades to deeplink (AC-5.1)", async () => {
    await renderWithTheme(
      <PlaybackPanel testID="playback" playlist={fixturePlaylist({ embedUrl: null })} providers={[provider()]} />,
    );
    expect(screen.getByRole("button", { name: "Open in Fixture One" })).toBeTruthy();
  });
});
