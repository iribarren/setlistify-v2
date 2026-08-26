import React from "react";
import { render, screen, waitFor } from "@testing-library/react-native";

import { PlaybackEmbed as WebPlaybackEmbed } from "@/components/playlist/PlaybackEmbed.web";
import { PlaybackEmbed as NativePlaybackEmbed } from "@/components/playlist/PlaybackEmbed.native";
import { ThemeProvider } from "@/theme";

function renderWithTheme(ui: React.ReactElement) {
  return render(<ThemeProvider>{ui}</ThemeProvider>);
}

/**
 * D-216: unit tests for each half of the one new platform fork, imported by their explicit file
 * names so the assertions hold regardless of which platform this Jest run's default resolution
 * picks for a bare `./PlaybackEmbed` import (see `PlaybackPanel.native.test.tsx`/`.web.test.tsx` for
 * that resolution-level proof).
 */
describe("PlaybackEmbed.web (AC-1.2, AC-1.3, AC-5.2, AC-6.3, D-223)", () => {
  it("renders an iframe with the url verbatim and no injected query parameter", async () => {
    await renderWithTheme(
      <WebPlaybackEmbed url="https://fixture.invalid/embed/1?already=there" onLoad={jest.fn()} onUnavailable={jest.fn()} />,
    );
    // react-native-web renders the real DOM node; RNTL's host-node query surfaces it.
    const iframe = screen.getByTestId("playback-embed-iframe");
    expect(iframe).toBeTruthy();
    expect(iframe.props.src).toBe("https://fixture.invalid/embed/1?already=there");
    expect(iframe.props.title).toBe("Playlist playback");
    expect(iframe.props.referrerPolicy).toBe("strict-origin-when-cross-origin");
  });

  it("calls onLoad on the iframe's load event", async () => {
    const onLoad = jest.fn();
    await renderWithTheme(<WebPlaybackEmbed url="https://fixture.invalid/embed/1" onLoad={onLoad} onUnavailable={jest.fn()} />);
    const iframe = screen.getByTestId("playback-embed-iframe");
    iframe.props.onLoad();
    expect(onLoad).toHaveBeenCalledTimes(1);
  });

  it("calls onUnavailable on the iframe's error event (AC-5.2 — a blocked/refused frame)", async () => {
    const onUnavailable = jest.fn();
    await renderWithTheme(<WebPlaybackEmbed url="https://fixture.invalid/embed/1" onLoad={jest.fn()} onUnavailable={onUnavailable} />);
    const iframe = screen.getByTestId("playback-embed-iframe");
    iframe.props.onError();
    expect(onUnavailable).toHaveBeenCalledTimes(1);
  });
});

describe("PlaybackEmbed.native (AC-5.8, D-216)", () => {
  it("mounts no player and reports unavailability on mount", async () => {
    const onUnavailable = jest.fn();
    const { toJSON } = await render(<NativePlaybackEmbed url="https://fixture.invalid/embed/1" onLoad={jest.fn()} onUnavailable={onUnavailable} />);
    await waitFor(() => expect(onUnavailable).toHaveBeenCalledTimes(1));
    expect(toJSON()).toBeNull();
  });
});
