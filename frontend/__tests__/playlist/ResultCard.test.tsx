import React from "react";
import { Linking } from "react-native";
import { fireEvent, render, screen } from "@testing-library/react-native";

import { ResultCard } from "@/components/playlist";
import type { PlaylistViewKind } from "@/lib/playlist";
import { ThemeProvider } from "@/theme";

function renderWithTheme(ui: React.ReactElement) {
  return render(<ThemeProvider>{ui}</ThemeProvider>);
}

const FORBIDDEN_WORDS = [/error/i, /failed/i, /\bproblem\b/i, /\bsorry\b/i];

describe("ResultCard (T-5, T-6, AC-4.3)", () => {
  it("result_full renders its headline, count and CTA", async () => {
    await renderWithTheme(
      <ResultCard
        testID="result"
        kind="result_full"
        job={{ matchedCount: 19, lowConfidenceCount: 0, songsTotal: 19, skippedCount: 0 }}
        playlist={{ "@id": "/api/playlists/1", "@type": "Playlist", externalUrl: "https://open.spotify.com/playlist/abc" }}
        providerDisplayName="Spotify"
        onSeeReport={jest.fn()}
      />,
    );
    expect(screen.getByText("19 / 19")).toBeTruthy();
    expect(screen.getByText("Every song's on the playlist")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Open in Spotify" })).toBeTruthy();
  });

  it("result_mostly leads with what's done and offers 'See what's missing' (D-171, not 'Review the N songs')", async () => {
    await renderWithTheme(
      <ResultCard
        testID="result"
        kind="result_mostly"
        job={{ matchedCount: 14, lowConfidenceCount: 0, songsTotal: 19, skippedCount: 0 }}
        playlist={null}
        providerDisplayName="Spotify"
        onSeeReport={jest.fn()}
      />,
    );
    expect(screen.getByText(/Playlist's ready/)).toBeTruthy();
    expect(screen.getByRole("button", { name: "See what's missing" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: /Review the \d+ songs/ })).toBeNull();
  });

  it.each(["result_mostly", "result_barely", "result_nothing"] as PlaylistViewKind[])(
    "%s carries no error token/word (AC-4.3, T-6)",
    async (kind) => {
      await renderWithTheme(
        <ResultCard
          testID="result"
          kind={kind as never}
          job={{ matchedCount: 4, lowConfidenceCount: 0, songsTotal: 19, skippedCount: 0 }}
          playlist={null}
          providerDisplayName="Spotify"
          onSeeReport={jest.fn()}
        />,
      );
      for (const word of FORBIDDEN_WORDS) {
        expect(screen.queryByText(word)).toBeNull();
      }
    },
  );

  it("result_nothing keeps a route to the report and never offers 'Open anyway' without a URL to open", async () => {
    await renderWithTheme(
      <ResultCard
        testID="result"
        kind="result_nothing"
        job={{ matchedCount: 0, lowConfidenceCount: 0, songsTotal: 12, skippedCount: 0 }}
        playlist={null}
        providerDisplayName="Spotify"
        onSeeReport={jest.fn()}
      />,
    );
    expect(screen.getByRole("button", { name: "See the full breakdown" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: /open .* anyway/i })).toBeNull();
  });

  it("result_nothing renders 'View the setlist' and opens sourceSetlists[0].url (T-10, D-186/AC-2.5)", async () => {
    const openURLSpy = jest.spyOn(Linking, "openURL").mockResolvedValue(true);

    await renderWithTheme(
      <ResultCard
        testID="result"
        kind="result_nothing"
        job={{ matchedCount: 0, lowConfidenceCount: 0, songsTotal: 12, skippedCount: 0 }}
        playlist={{
          "@id": "/api/playlists/1",
          "@type": "Playlist",
          sourceSetlists: [
            { bandName: "Some Band", setlistfmId: "abc123", url: "https://www.setlist.fm/setlist/some-band/2026/venue-abc123.html" },
          ],
        }}
        providerDisplayName="Spotify"
        onSeeReport={jest.fn()}
      />,
    );

    const button = screen.getByRole("button", { name: "View the setlist" });
    expect(button).toBeTruthy();
    fireEvent.press(button);
    expect(openURLSpy).toHaveBeenCalledWith("https://www.setlist.fm/setlist/some-band/2026/venue-abc123.html");

    openURLSpy.mockRestore();
  });

  it("result_nothing with an empty sourceSetlists renders no 'View the setlist' button — absent, never disabled (AC-2.5)", async () => {
    await renderWithTheme(
      <ResultCard
        testID="result"
        kind="result_nothing"
        job={{ matchedCount: 0, lowConfidenceCount: 0, songsTotal: 12, skippedCount: 0 }}
        playlist={{ "@id": "/api/playlists/1", "@type": "Playlist", sourceSetlists: [] }}
        providerDisplayName="Spotify"
        onSeeReport={jest.fn()}
      />,
    );

    expect(screen.queryByRole("button", { name: "View the setlist" })).toBeNull();
  });

  it("result_nothing with a null sourceSetlists[0].url renders no 'View the setlist' button (a setlist cached before this branch, AC-2.5/AC-2.7)", async () => {
    await renderWithTheme(
      <ResultCard
        testID="result"
        kind="result_nothing"
        job={{ matchedCount: 0, lowConfidenceCount: 0, songsTotal: 12, skippedCount: 0 }}
        playlist={{
          "@id": "/api/playlists/1",
          "@type": "Playlist",
          sourceSetlists: [{ bandName: "Some Band", setlistfmId: "abc123", url: null }],
        }}
        providerDisplayName="Spotify"
        onSeeReport={jest.fn()}
      />,
    );

    expect(screen.queryByRole("button", { name: "View the setlist" })).toBeNull();
  });
});
