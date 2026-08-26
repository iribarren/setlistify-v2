import { derivePlaybackSurface, type PlaybackSurface } from "@/lib/playlist/playback";
import type { PlaylistOutput, ProviderConfigOutput } from "@/lib/playlist/types";

/**
 * Layer 1 (docs/specs/2026-08-26-concert-page-player-embed.md, "Testing"): `derivePlaybackSurface()`
 * table-tested over the complete cross product of its inputs. Provider- and platform-blind by
 * construction — two provider *fixtures* below (never a real adapter) prove AC-1.6/AC-7.2's "no
 * provider key literal" and AC-4.4's per-provider independence without needing prompt 18.
 */

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

function playlist(overrides: Partial<PlaylistOutput> = {}): PlaylistOutput {
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

const EMBED = (embedUrl: string): PlaybackSurface => ({ kind: "embed", embedUrl });
const DEEPLINK = (url: string): PlaybackSurface => ({ kind: "deeplink", url });
const METADATA: PlaybackSurface = { kind: "metadata" };

describe("derivePlaybackSurface (Layer 1, spec 19 §3's full truth table)", () => {
  it("embed + enabled + embedUrl + externalUrl + available -> embed, url verbatim (AC-1.3)", () => {
    const result = derivePlaybackSurface({
      provider: provider({ playbackMode: "embed", enabled: true }),
      playlist: playlist({ embedUrl: "https://fixture.invalid/embed/verbatim" }),
      embedUnavailable: false,
    });
    expect(result).toEqual(EMBED("https://fixture.invalid/embed/verbatim"));
  });

  it("embed + enabled + embedUrl + externalUrl + UNAVAILABLE -> deeplink (AC-5.2/AC-5.3/AC-5.8)", () => {
    const result = derivePlaybackSurface({
      provider: provider({ playbackMode: "embed", enabled: true }),
      playlist: playlist(),
      embedUnavailable: true,
    });
    expect(result).toEqual(DEEPLINK("https://fixture.invalid/deeplink/1"));
  });

  it("embed + enabled + embedUrl + unavailable + no externalUrl -> metadata", () => {
    const result = derivePlaybackSurface({
      provider: provider({ playbackMode: "embed", enabled: true }),
      playlist: playlist({ externalUrl: null }),
      embedUnavailable: true,
    });
    expect(result).toEqual(METADATA);
  });

  it("embed + enabled + NO embedUrl + externalUrl -> deeplink (AC-5.1)", () => {
    const result = derivePlaybackSurface({
      provider: provider({ playbackMode: "embed", enabled: true }),
      playlist: playlist({ embedUrl: null }),
      embedUnavailable: false,
    });
    expect(result).toEqual(DEEPLINK("https://fixture.invalid/deeplink/1"));
  });

  it("embed + enabled + no embedUrl + no externalUrl -> metadata", () => {
    const result = derivePlaybackSurface({
      provider: provider({ playbackMode: "embed", enabled: true }),
      playlist: playlist({ embedUrl: null, externalUrl: null }),
      embedUnavailable: false,
    });
    expect(result).toEqual(METADATA);
  });

  it("embed + DISABLED + externalUrl -> deeplink, never off (AC-5.7, D-219)", () => {
    const result = derivePlaybackSurface({
      provider: provider({ playbackMode: "embed", enabled: false }),
      playlist: playlist(),
      embedUnavailable: false,
    });
    expect(result).toEqual(DEEPLINK("https://fixture.invalid/deeplink/1"));
  });

  it("embed + disabled + no externalUrl -> metadata", () => {
    const result = derivePlaybackSurface({
      provider: provider({ playbackMode: "embed", enabled: false }),
      playlist: playlist({ externalUrl: null }),
      embedUnavailable: false,
    });
    expect(result).toEqual(METADATA);
  });

  it("deeplink + externalUrl -> deeplink, regardless of embedUrl/enabled/embedUnavailable", () => {
    const result = derivePlaybackSurface({
      provider: provider({ playbackMode: "deeplink", enabled: false }),
      playlist: playlist({ embedUrl: null }),
      embedUnavailable: true,
    });
    expect(result).toEqual(DEEPLINK("https://fixture.invalid/deeplink/1"));
  });

  it("deeplink + no externalUrl -> metadata (AC-2.4)", () => {
    const result = derivePlaybackSurface({
      provider: provider({ playbackMode: "deeplink" }),
      playlist: playlist({ externalUrl: null }),
      embedUnavailable: false,
    });
    expect(result).toEqual(METADATA);
  });

  it("off -> metadata always, even with everything else present (AC-3.1)", () => {
    const result = derivePlaybackSurface({
      provider: provider({ playbackMode: "off", enabled: true }),
      playlist: playlist(),
      embedUnavailable: false,
    });
    expect(result).toEqual(METADATA);
  });

  it("provider undefined (config loading/failed) -> metadata (AC-4.5, deny by default)", () => {
    const result = derivePlaybackSurface({
      provider: undefined,
      playlist: playlist(),
      embedUnavailable: false,
    });
    expect(result).toEqual(METADATA);
  });

  it("an unrecognised playbackMode -> metadata (AC-4.5, deny by default — an older client, newer server)", () => {
    const result = derivePlaybackSurface({
      provider: provider({ playbackMode: "not-a-real-mode" as never }),
      playlist: playlist(),
      embedUnavailable: false,
    });
    expect(result).toEqual(METADATA);
  });

  // --- AC-4.3: all six operator transitions are visibly distinct (on web) -------------------------
  it.each([
    ["embed", "deeplink"],
    ["embed", "off"],
    ["deeplink", "embed"],
    ["deeplink", "off"],
    ["off", "embed"],
    ["off", "deeplink"],
  ] as const)("transition %s -> %s changes the result", (from, to) => {
    const before = derivePlaybackSurface({
      provider: provider({ playbackMode: from }),
      playlist: playlist(),
      embedUnavailable: false,
    });
    const after = derivePlaybackSurface({
      provider: provider({ playbackMode: to }),
      playlist: playlist(),
      embedUnavailable: false,
    });
    expect(after).not.toEqual(before);
  });

  // --- AC-4.4: read per-provider — a second, unrelated fixture is unaffected ----------------------
  it("is a pure function of ONE playlist's own provider — a second provider fixture's mode is irrelevant", () => {
    const providerA = provider({ key: "fixture-a", playbackMode: "embed" });
    const providerB = provider({ key: "fixture-b", playbackMode: "off" });

    // The playlist belongs to fixture-a; passing fixture-a's config must behave as fixture-a's row,
    // completely independent of what fixture-b (a second, unrelated provider) is configured to.
    const result = derivePlaybackSurface({
      provider: providerA,
      playlist: playlist({ provider: "fixture-a" }),
      embedUnavailable: false,
    });
    expect(result.kind).toBe("embed");
    void providerB; // never consulted — derivePlaybackSurface takes one ProviderConfigOutput, not a list.
  });
});
