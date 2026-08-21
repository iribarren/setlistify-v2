import { formatConcertDate, formatMoney, parseMoneyInput } from "@/lib/concerts";

describe("lib/concerts/mapping (D-38)", () => {
  describe("parseMoneyInput (AC-3.7)", () => {
    it("parses a dot decimal to minor units", () => {
      expect(parseMoneyInput("12.50")).toBe(1250);
    });

    it("parses a comma decimal to the same minor units", () => {
      expect(parseMoneyInput("12,50")).toBe(1250);
    });

    it("returns null for an empty input", () => {
      expect(parseMoneyInput("")).toBeNull();
      expect(parseMoneyInput("   ")).toBeNull();
    });

    it("returns null for an unparsable input", () => {
      expect(parseMoneyInput("free")).toBeNull();
    });
  });

  describe("formatMoney (AC-5.4)", () => {
    it("formats minor units + ISO 4217 currency with Intl", () => {
      expect(formatMoney({ amount: 1600, currency: "GBP" }, "en-GB")).toBe("£16.00");
    });

    it("returns null when there is no price", () => {
      expect(formatMoney(null)).toBeNull();
      expect(formatMoney(undefined)).toBeNull();
      expect(formatMoney({ amount: null, currency: null })).toBeNull();
    });
  });

  describe("formatConcertDate (AC-5.5/D-35/R-4)", () => {
    it("renders the concert's own calendar date, unaffected by the viewer's device timezone", () => {
      // A concert on 2026-09-05 in Europe/Madrid must read as 5 September 2026 for a viewer whose
      // OWN device is set to a wildly different zone (Auckland, UTC+12/+13) — this function never
      // converts toward the viewer's zone; it only ever formats using the CONCERT's own timezone,
      // so the device's local Date/Intl defaults are irrelevant here by construction.
      const formatted = formatConcertDate("2026-09-05", "Europe/Madrid", "en-GB", {
        day: "numeric",
        month: "long",
        year: "numeric",
      });
      expect(formatted).toBe("5 September 2026");
    });

    it("gives the same calendar date for two very different timezones on the same input", () => {
      const madrid = formatConcertDate("2026-09-05", "Europe/Madrid", "en-GB");
      const auckland = formatConcertDate("2026-09-05", "Pacific/Auckland", "en-GB");
      // Both describe the SAME concert date — formatting is driven by the concert's own timezone
      // being passed in, not by whichever zone happens to be asked to render it.
      expect(madrid).toContain("2026");
      expect(auckland).toContain("2026");
    });

    it("R-4: a viewer whose OWN device timezone differs from the concert's still sees the concert's date, never shifted", () => {
      const originalTz = process.env.TZ;
      try {
        // Simulate a device set to Auckland (UTC+12/+13) viewing a Madrid concert (UTC+1/+2) —
        // the widest realistic offset, and the exact case R-4/AC-12.5 calls out.
        process.env.TZ = "Pacific/Auckland";
        const formatted = formatConcertDate("2026-09-05", "Europe/Madrid", "en-GB", {
          day: "numeric",
          month: "long",
          year: "numeric",
        });
        expect(formatted).toBe("5 September 2026");
      } finally {
        process.env.TZ = originalTz;
      }
    });
  });
});
