import type { PlaylistOutput } from "@/lib/playlist";

import { refreshCanHelp } from "./types";

export interface RefreshableBand {
  bandId: number;
  bandName: string;
  cause: string;
}

/**
 * D-269's "where the failure is displayed" needs a band id to call `POST
 * /api/bands/{bandId}/setlist-refresh` with — but neither `PlaylistGenerationJobOutput.noSetlistCause`
 * (folded to a single value across the whole lineup, `NoSetlistCauseFolder`) nor the pre-existing
 * `NO_SETLIST_FOR_BAND` report entry (`{band: name, cause}`) carried one. Backend gap found and
 * fixed while wiring this feature (docs/specs/2026-08-27-instant-setlist-refresh.md, US-10):
 * `SetlistSelectionStage::buildSkeleton()` and `SetlistChoiceApplier` now also write `bandId` into
 * that report entry's `params` (same precedent as the `SongOutput.id` gap fixed for notes-and-reviews
 * — see the `[[notes_and_reviews_ui]]` memory entry).
 *
 * Reads `playlist.report` directly rather than the job's folded `noSetlistCause`, so a lineup with
 * more than one affected band gets one action per band instead of only the folded one.
 */
export function bandsNeedingSetlist(playlist: PlaylistOutput | null | undefined): RefreshableBand[] {
  if (!playlist?.report) {
    return [];
  }

  const result: RefreshableBand[] = [];
  for (const entry of playlist.report) {
    if (entry.code !== "NO_SETLIST_FOR_BAND") {
      continue;
    }
    const params = entry.params ?? {};
    const bandName = typeof params.band === "string" ? params.band : null;
    const cause = typeof params.cause === "string" ? params.cause : null;
    // `params` is typed `{[key: string]: string | null}` (a generic report-entry shape shared by
    // every code), but `bandId` travels as a real JSON number on the wire — `Number()` handles both.
    const bandId = Number(params.bandId as unknown);

    if (bandName == null || !Number.isFinite(bandId) || bandId <= 0 || !refreshCanHelp(cause)) {
      continue;
    }

    result.push({ bandId, bandName, cause: cause as string });
  }
  return result;
}
