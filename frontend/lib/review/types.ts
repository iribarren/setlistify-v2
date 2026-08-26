import type { components } from "@/api";

// AC-11.1/T-17-style discipline (mirrors `lib/playlist/types.ts`): every wire shape this feature
// touches is aliased from the generated schema here — nothing else in the module re-declares one.
export type ConcertReviewOutput = components["schemas"]["ConcertReview.ConcertReviewOutput.jsonld"];
export type ConcertReviewInput = components["schemas"]["ConcertReview.ConcertReviewInput"];
export type ConcertReviewSummaryOutput = components["schemas"]["ConcertReviewSummaryOutput.jsonld"];

export type SongOutput = components["schemas"]["SongOutput.jsonld"];
export type SetlistSummaryOutput = components["schemas"]["SetlistSummaryOutput.jsonld"];
export type SetlistDetailOutput = components["schemas"]["Setlist.SetlistDetailOutput.jsonld"];
export type BandSetlistsOutput = components["schemas"]["BandSetlists.BandSetlistsOutput.jsonld"];

/** One band's persisted-setlist songs, in setlist order, for the structured highlight (AC-5.1). */
export interface HighlightBandGroup {
  bandId: number;
  bandName: string;
  songs: { songId: number; title: string }[];
}
