import AsyncStorage from "@react-native-async-storage/async-storage";

import type { CachedConcert } from "@/lib/concerts";
import { dismissReviewPrompt, isReviewPromptCandidate, isReviewPromptDismissed, pastReviewPromptCandidates } from "@/lib/review";

const NOW = new Date("2026-08-26T12:00:00Z");

function concert(overrides: Partial<CachedConcert> = {}): CachedConcert {
  return {
    id: 1,
    date: "2026-08-01",
    timezone: "Europe/Madrid",
    status: "past",
    lineup: [],
    venue: {},
    reviewSummary: null,
    createdAt: "2026-01-01T00:00:00+00:00",
    updatedAt: "2026-01-01T00:00:00+00:00",
    ...overrides,
  } as CachedConcert;
}

describe("lib/review/prompt (US-7, D-242)", () => {
  afterEach(async () => {
    await AsyncStorage.clear();
  });

  it("AC-7.2: a past, unreviewed, recent concert is a candidate", () => {
    expect(isReviewPromptCandidate(concert(), NOW)).toBe(true);
  });

  it("AC-7.6: an upcoming concert is never a candidate", () => {
    expect(isReviewPromptCandidate(concert({ status: "upcoming" }), NOW)).toBe(false);
  });

  it("AC-7.4/US-7: an already-reviewed concert is never a candidate", () => {
    expect(
      isReviewPromptCandidate(
        concert({ reviewSummary: { rating: 5, highlightTitle: null, updatedAt: "2026-08-02T00:00:00+00:00" } }),
        NOW,
      ),
    ).toBe(false);
  });

  it("AC-7.6: a concert older than the 30-day window is never a candidate", () => {
    expect(isReviewPromptCandidate(concert({ date: "2020-01-01" }), NOW)).toBe(false);
  });

  it("pastReviewPromptCandidates preserves order and filters out ineligible concerts", () => {
    const recent = concert({ id: 1, date: "2026-08-20" });
    const reviewed = concert({ id: 2, date: "2026-08-21", reviewSummary: { rating: 4, highlightTitle: null, updatedAt: "x" } });
    const stale = concert({ id: 3, date: "2020-01-01" });
    expect(pastReviewPromptCandidates([recent, reviewed, stale], NOW)).toEqual([recent]);
  });

  it("AC-7.3: a dismissed concert stays dismissed across independent checks (persisted, not in-memory)", async () => {
    expect(await isReviewPromptDismissed("1")).toBe(false);
    await dismissReviewPrompt("1");
    expect(await isReviewPromptDismissed("1")).toBe(true);
  });
});
