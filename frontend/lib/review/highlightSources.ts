import { useQueries } from "@tanstack/react-query";

import { apiClient, unwrap } from "@/lib/api";
import type { ConcertOutput } from "@/lib/concerts";

import type { HighlightBandGroup } from "./types";

/**
 * AC-5.1/AC-5.6: the structured highlight picker's data source — persisted `Setlist`/`Song` rows
 * ONLY (D-232). Never calls `SetlistGateway`/setlist.fm directly: `GET /api/bands/{bandId}/setlists`
 * and `GET /api/setlists/{setlistfmId}` both serve from the cached/durable tier the same way the
 * rest of the app does (spec 09) — this hook spends none of the 1,440/day budget itself.
 *
 * For each band in the concert's lineup, "the setlist for this show" is the band's cached setlist
 * whose `eventDate` matches the concert's own date — the same "this specific gig" identity the
 * playlist feature's `isSameNight` flag captures at generation time (`SetlistPicker.tsx`), applied
 * here against the band's already-cached setlist index rather than live setlist.fm candidates.
 */
export interface UseHighlightSourcesResult {
  /** `true` until every band's lookup has settled. */
  loading: boolean;
  /** One entry per band with a matching, non-empty cached setlist, in lineup order. */
  groups: HighlightBandGroup[];
  /** AC-5.1/AC-5.2: whether the picker should render at all, vs. the plain-text fallback. */
  hasSetlist: boolean;
}

interface LineupBand {
  id: number;
  name: string;
}

function lineupBands(concert: ConcertOutput | undefined): LineupBand[] {
  return (concert?.lineup ?? [])
    .map((entry) => entry.band)
    .filter((band): band is { id: number; name: string } => band?.id != null && band?.name != null)
    .map((band) => ({ id: band.id as number, name: band.name as string }));
}

export function useHighlightSources(concert: ConcertOutput | undefined): UseHighlightSourcesResult {
  const bands = lineupBands(concert);
  const concertDate = concert?.date;

  const setlistIndexQueries = useQueries({
    queries: bands.map((band) => ({
      queryKey: ["review", "band-setlists", band.id] as const,
      queryFn: async () =>
        unwrap(async (signal) =>
          apiClient.GET("/api/bands/{bandId}/setlists", {
            params: { path: { bandId: String(band.id) }, query: { itemsPerPage: 100 } },
            signal,
          }),
        ),
      enabled: Boolean(concert),
      staleTime: 5 * 60 * 1000,
    })),
  });

  const matches = bands
    .map((band, index) => {
      const setlists = setlistIndexQueries[index]?.data?.setlists ?? [];
      const match = concertDate ? setlists.find((setlist) => setlist.eventDate === concertDate) : undefined;
      return match?.setlistfmId ? { band, setlistfmId: match.setlistfmId } : null;
    })
    .filter((entry): entry is { band: LineupBand; setlistfmId: string } => entry != null);

  const setlistDetailQueries = useQueries({
    queries: matches.map(({ setlistfmId }) => ({
      queryKey: ["review", "setlist-detail", setlistfmId] as const,
      queryFn: async () =>
        unwrap(async (signal) =>
          apiClient.GET("/api/setlists/{setlistfmId}", { params: { path: { setlistfmId } }, signal }),
        ),
      staleTime: 60 * 60 * 1000,
    })),
  });

  const groups: HighlightBandGroup[] = matches
    .map(({ band }, index) => {
      const detail = setlistDetailQueries[index]?.data;
      const songs = (detail?.songs ?? [])
        .filter((song): song is typeof song & { id: number; title: string } => song.id != null)
        .slice()
        .sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
        .map((song) => ({ songId: song.id, title: song.title }));
      return { bandId: band.id, bandName: band.name, songs };
    })
    .filter((group) => group.songs.length > 0);

  const loading =
    Boolean(concert) &&
    (setlistIndexQueries.some((q) => q.isLoading) || setlistDetailQueries.some((q) => q.isLoading));

  return { loading, groups, hasSetlist: groups.length > 0 };
}
