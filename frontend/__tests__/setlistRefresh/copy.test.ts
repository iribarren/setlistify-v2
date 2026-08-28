import { formatRetryAt, pickRefusalCopy, refusalCopy } from "@/lib/setlistRefresh";
import { REFUSED_REASONS, type RefusedReason } from "@/lib/setlistRefresh";

describe("refusalCopy (AC-4.2/AC-4.5/AC-10.5)", () => {
  it("gives each of the six refusal reasons distinct copy naming a return time", () => {
    const retryAt = "2026-08-27T16:40:00+00:00";
    const messages = new Set<string>();
    for (const reason of REFUSED_REASONS) {
      const copy = refusalCopy(reason as RefusedReason, retryAt);
      expect(copy).toContain(formatRetryAt(retryAt));
      messages.add(copy);
    }
    // Distinguishable by field value alone (AC-4.5) -> distinguishable copy too.
    expect(messages.size).toBe(REFUSED_REASONS.length);
  });

  it("falls back to 'shortly' for a missing retryAfterAt", () => {
    expect(formatRetryAt(null)).toBe("shortly");
    expect(formatRetryAt(undefined)).toBe("shortly");
    expect(formatRetryAt("not-a-date")).toBe("shortly");
  });
});

describe("pickRefusalCopy (AC-4.7/AC-10.10)", () => {
  it("frames band_already_resolved as a normal outcome, not an error", () => {
    const copy = pickRefusalCopy("band_already_resolved");
    expect(copy.toLowerCase()).toContain("someone else");
    expect(copy.toLowerCase()).not.toContain("error");
  });

  it("gives mbid_not_a_candidate its own distinct copy", () => {
    expect(pickRefusalCopy("mbid_not_a_candidate")).not.toBe(pickRefusalCopy("band_already_resolved"));
  });
});
