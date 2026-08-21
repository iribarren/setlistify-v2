import type { components } from "@/api";

// AC-12.2: every concert/band/lineup/venue/money shape used in this feature is imported from the
// generated schema — nothing here re-declares a request or response shape by hand.
export type ConcertOutput = components["schemas"]["Concert.ConcertOutput.jsonld"];
export type ConcertInput = components["schemas"]["Concert.ConcertInput"];
export type ConcertPatchInput = components["schemas"]["Concert.ConcertPatchInput.jsonMergePatch"];
export type LineupEntryInput = components["schemas"]["LineupEntryInput"];
export type LineupEntryOutput = components["schemas"]["LineupEntryOutput.jsonld"];
export type BandOutput = components["schemas"]["BandOutput.jsonld"];
export type VenueData = components["schemas"]["VenueData"];
export type MoneyData = components["schemas"]["MoneyData"];
export type ConstraintViolation = components["schemas"]["ConstraintViolation"];
export type Violation = NonNullable<ConstraintViolation["violations"]>[number];

/** D-32: the two independent sections the list screen queries. */
export type ConcertSectionStatus = "upcoming" | "past";
