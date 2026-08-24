import { describeReportCode, REPORT_CODES } from "@/lib/playlist";

describe("describeReportCode (T-3, D-167)", () => {
  it("is total over every generated report code", () => {
    for (const code of REPORT_CODES) {
      const sentence = describeReportCode(code, {});
      expect(typeof sentence).toBe("string");
      expect(sentence.length).toBeGreaterThan(0);
      // AC-5.3: never the code itself.
      expect(sentence).not.toContain(code);
    }
  });

  it("interpolates params (COVER_OF names the artist)", () => {
    expect(describeReportCode("COVER_OF", { artist: "Nine Inch Nails" })).toContain("Nine Inch Nails");
  });

  it("NO_SETLIST_FOR_BAND names the band", () => {
    expect(describeReportCode("NO_SETLIST_FOR_BAND", { band: "Iceage" })).toContain("Iceage");
  });

  it("an unknown runtime code falls back to a safe sentence, never the code itself", () => {
    const sentence = describeReportCode("SOME_FUTURE_CODE_NOT_YET_KNOWN", {});
    expect(sentence).not.toContain("SOME_FUTURE_CODE_NOT_YET_KNOWN");
    expect(sentence.length).toBeGreaterThan(0);
  });

  it("a null/undefined code falls back to the safe sentence too", () => {
    expect(describeReportCode(null, {})).toBeTruthy();
    expect(describeReportCode(undefined, {})).toBeTruthy();
  });
});
