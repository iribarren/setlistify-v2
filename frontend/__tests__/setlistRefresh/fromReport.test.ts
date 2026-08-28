import { bandsNeedingSetlist } from "@/lib/setlistRefresh";
import type { PlaylistOutput } from "@/lib/playlist";

function playlist(report: PlaylistOutput["report"]): PlaylistOutput {
  return {
    id: 1,
    concertId: 1,
    provider: "spotify",
    name: "Test playlist",
    description: null,
    externalUrl: null,
    embedUrl: null,
    resultKind: "no_source_material",
    noSetlistCause: "band_unknown",
    matchRate: 0,
    createdAt: "2026-08-27T00:00:00+00:00",
    report,
    tracks: [],
    sourceSetlists: [],
  } as unknown as PlaylistOutput;
}

describe("bandsNeedingSetlist (US-10, AC-10.2)", () => {
  it("extracts bandId/bandName/cause from NO_SETLIST_FOR_BAND report entries", () => {
    const bands = bandsNeedingSetlist(
      playlist([
        { code: "NO_SETLIST_FOR_BAND", params: { band: "Boikot", bandId: 42 as unknown as string, cause: "band_ambiguous" } },
        { code: "SELECTED_FROM", params: { band: "Other Band" } },
      ]),
    );

    expect(bands).toEqual([{ bandId: 42, bandName: "Boikot", cause: "band_ambiguous" }]);
  });

  it("skips an entry missing a usable bandId", () => {
    const bands = bandsNeedingSetlist(
      playlist([{ code: "NO_SETLIST_FOR_BAND", params: { band: "Boikot", cause: "band_ambiguous" } }]),
    );
    expect(bands).toEqual([]);
  });

  it("returns an empty list for a null playlist or missing report", () => {
    expect(bandsNeedingSetlist(null)).toEqual([]);
    expect(bandsNeedingSetlist(playlist(undefined as unknown as PlaylistOutput["report"]))).toEqual([]);
  });

  it("supports more than one affected band in a lineup", () => {
    const bands = bandsNeedingSetlist(
      playlist([
        { code: "NO_SETLIST_FOR_BAND", params: { band: "A", bandId: 1 as unknown as string, cause: "band_unknown" } },
        { code: "NO_SETLIST_FOR_BAND", params: { band: "B", bandId: 2 as unknown as string, cause: "identity_unavailable" } },
      ]),
    );
    expect(bands.map((b) => b.bandId)).toEqual([1, 2]);
  });
});
