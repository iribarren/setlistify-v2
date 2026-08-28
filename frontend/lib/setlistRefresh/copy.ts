import type { RefusedReason } from "./types";

/** AC-4.3: renders an absolute UTC instant as a short local time, e.g. "4:40 PM" — "come back at
 * T", not "in a while" (D-260). Falls back to "shortly" for a missing/unparseable instant. */
export function formatRetryAt(retryAfterAt: string | null | undefined): string {
  if (!retryAfterAt) {
    return "shortly";
  }
  const date = new Date(retryAfterAt);
  if (Number.isNaN(date.getTime())) {
    return "shortly";
  }
  return date.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
}

/**
 * AC-4.2/AC-10.5: distinct, human copy for each of the six refusal reasons, each naming the return
 * time (D-260's "not now, come back at T"). One `Record`, exhaustively checked against
 * `RefusedReason` at compile time — a seventh reason the client doesn't recognise yet is handled by
 * `refusalCopy`'s fallback branch, not by widening this map.
 */
const REFUSAL_COPY: Record<RefusedReason, (retryAt: string) => string> = {
  cooldown_active: (retryAt) =>
    `This band was just checked — try again around ${retryAt}. A repeat look wouldn't find anything new yet.`,
  daily_limit_reached: (retryAt) =>
    `You've used today's refreshes. More open up around ${retryAt}.`,
  budget_reserved: (retryAt) =>
    `Setlistify is holding back today's last lookups for everyone. Try again around ${retryAt}.`,
  budget_exhausted: (retryAt) =>
    `Today's shared setlist.fm lookups are spent. Try again around ${retryAt}.`,
  rate_limited: (retryAt) =>
    `setlist.fm is asking us to slow down — try again around ${retryAt}.`,
  upstream_unavailable: (retryAt) =>
    `We couldn't reach setlist.fm just now. Try again around ${retryAt}.`,
};

export function refusalCopy(reason: RefusedReason | null | undefined, retryAfterAt: string | null | undefined): string {
  const retryAt = formatRetryAt(retryAfterAt);
  if (reason && reason in REFUSAL_COPY) {
    return REFUSAL_COPY[reason](retryAt);
  }
  return `Not available right now. Try again around ${retryAt}.`;
}

/** AC-10.10: `mbid_not_a_candidate` and `band_already_resolved` are 422s, not 429s — their own copy. */
export function pickRefusalCopy(reason: "mbid_not_a_candidate" | "band_already_resolved"): string {
  if (reason === "band_already_resolved") {
    // AC-10.10: this is a normal outcome, not an error — another user resolved this band first.
    return "Someone else just resolved this band. Its setlists should be on the way — refresh to see them.";
  }
  return "That choice isn't available any more. Look again to get a fresh list of candidates.";
}
