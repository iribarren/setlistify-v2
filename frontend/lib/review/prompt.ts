import { useEffect, useRef, useState } from "react";

import type { CachedConcert } from "@/lib/concerts";

import { reviewPromptStorage } from "./reviewPromptStorage";

const WINDOW_DAYS = 30;

function dismissKey(concertId: string): string {
  return `review-prompt-dismissed:${concertId}`;
}

/** D-242 §10: the pure selection rule, kept testable without a screen. */
export function isReviewPromptCandidate(
  concert: CachedConcert,
  now: Date = new Date(),
  windowDays: number = WINDOW_DAYS,
): boolean {
  if (concert.status !== "past" || concert.reviewSummary || concert.id == null || !concert.date) {
    return false;
  }
  const windowStart = new Date(now);
  windowStart.setDate(windowStart.getDate() - windowDays);
  return concert.date >= windowStart.toISOString().slice(0, 10);
}

/**
 * D-242 §10: `pastConcerts`, most-recent-first (the order `useConcertsSection("past")` already
 * returns), filtered to unreviewed shows that became past within the last 30 days — dismissal is
 * applied separately by the hook below, asynchronously, so this stays a synchronous pure function.
 */
export function pastReviewPromptCandidates(
  pastConcertsMostRecentFirst: CachedConcert[],
  now: Date = new Date(),
  windowDays: number = WINDOW_DAYS,
): CachedConcert[] {
  return pastConcertsMostRecentFirst.filter((concert) => isReviewPromptCandidate(concert, now, windowDays));
}

export async function isReviewPromptDismissed(concertId: string): Promise<boolean> {
  try {
    return (await reviewPromptStorage.getItem(dismissKey(concertId))) != null;
  } catch {
    return false; // Best-effort — a storage failure never blocks the nudge from showing.
  }
}

export async function dismissReviewPrompt(concertId: string): Promise<void> {
  try {
    await reviewPromptStorage.setItem(dismissKey(concertId), "1");
  } catch {
    // Best-effort (D-242: this is convenience state, not user content).
  }
}

export interface UseReviewPromptCardResult {
  /** The one concert to nudge about, or `null` when none qualifies (or the list is still loading). */
  concert: CachedConcert | null;
  dismiss: () => void;
}

/**
 * AC-7.1–AC-7.4: picks at most one concert to nudge about, evaluated ONCE per mount (AC-7.3) — a
 * dismissal hides the card for the rest of this mount rather than revealing the next candidate
 * immediately. `ready` must be `false` while the past section's first page is still loading —
 * called unconditionally every render (rules of hooks), it just defers its one-shot pick until
 * `ready` flips true, rather than resolving prematurely against an empty, still-loading list.
 */
export function useReviewPromptCard(
  pastConcertsMostRecentFirst: CachedConcert[],
  ready: boolean,
): UseReviewPromptCardResult {
  const [concert, setConcert] = useState<CachedConcert | null>(null);
  const resolvedRef = useRef(false);
  const candidates = pastReviewPromptCandidates(pastConcertsMostRecentFirst);

  useEffect(() => {
    if (resolvedRef.current || !ready) {
      return;
    }
    resolvedRef.current = true;
    let cancelled = false;
    // `candidates` is read here as it stood at the render that flipped `ready` true — exactly the
    // one-shot pick AC-7.3 wants (deliberately NOT a dependency: re-running this on every later
    // `candidates` change would repeat the pick on each list refetch, which is what "ready" guards
    // against in the first place).
    void (async () => {
      for (const candidate of candidates) {
        if (candidate.id == null) {
          continue;
        }
        // Sequential by design — stop at the first winner rather than resolving all in parallel.
        const dismissed = await isReviewPromptDismissed(String(candidate.id));
        if (!dismissed) {
          if (!cancelled) {
            setConcert(candidate);
          }
          return;
        }
      }
      if (!cancelled) {
        setConcert(null);
      }
    })();
    return () => {
      cancelled = true;
    };
    // Re-runs only until `ready` flips true, then never again this mount (guarded by resolvedRef,
    // not by this dependency array) — AC-7.3.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ready]);

  // AC-7.4: a review just written for the shown concert removes its card without a dismissal — no
  // re-pick this mount, just stop showing a card that's no longer a candidate. Compares a derived
  // BOOLEAN (not the `candidates` array itself, which is a fresh reference every render and would
  // never settle) against a value held in state — React's documented pattern for adjusting state
  // from a changed input during render, same shape as `ConcertForm`'s `previousViolations` sync.
  const stillCandidate = concert == null || candidates.some((candidate) => candidate.id === concert.id);
  const [wasStillCandidate, setWasStillCandidate] = useState(stillCandidate);
  if (wasStillCandidate !== stillCandidate) {
    setWasStillCandidate(stillCandidate);
    if (!stillCandidate) {
      setConcert(null);
    }
  }

  function dismiss(): void {
    if (!concert || concert.id == null) {
      return;
    }
    void dismissReviewPrompt(String(concert.id));
    setConcert(null); // AC-7.3: no replacement card this mount.
  }

  return { concert, dismiss };
}
