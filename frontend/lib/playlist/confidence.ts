import type { BadgeVariant } from "@/components";

import { asConfidenceLabel, type ConfidenceLabel } from "./types";

/**
 * D-204/AC-2.5: maps a backend `label` to a rendered chip and nothing else — no client-side scoring,
 * no raw confidence number, digit-plus-percent, or star glyph anywhere in this file or in anything
 * that consumes it. This is the ONE place that vocabulary is turned into copy.
 */
export interface ConfidenceChip {
  label: string;
  reason: string;
  variant: BadgeVariant;
}

const CONFIDENCE_COPY: Record<ConfidenceLabel, ConfidenceChip> = {
  top_pick: { label: "Top pick", reason: "The closest match we found.", variant: "info" },
  only_match: { label: "Only match", reason: "Nothing else came close.", variant: "info" },
  alternative: { label: "Alternative", reason: "Another version of this song.", variant: "neutral" },
  your_previous_choice: {
    label: "Your previous choice",
    reason: "You picked this version last time.",
    variant: "success",
  },
};

/** An unrecognised label (client older than server) degrades to a neutral, honest chip — never a crash. */
const UNKNOWN_LABEL_FALLBACK: ConfidenceChip = {
  label: "Candidate",
  reason: "One of the versions we found.",
  variant: "neutral",
};

export function describeConfidence(label: string | null | undefined): ConfidenceChip {
  const known = asConfidenceLabel(label ?? undefined);
  if (!known) {
    if (__DEV__ && label) {
      console.warn(`[playlist] Unrecognised confidence label from the server: ${label}`);
    }
    return UNKNOWN_LABEL_FALLBACK;
  }
  return CONFIDENCE_COPY[known];
}
