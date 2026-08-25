import { CONFIDENCE_LABELS, describeConfidence } from "@/lib/playlist";

// AC-2.5/D-204: no raw confidence number, digit-plus-percent, or star glyph anywhere in this module.
const FORBIDDEN_PATTERNS = [/%\s*$/, /\d+\s*%/, /★|☆|⭐/, /\bconfidence\b/i, /\bscore\b/i];

describe("describeConfidence (D-204/AC-2.5)", () => {
  it("is total over every generated confidence label", () => {
    for (const label of CONFIDENCE_LABELS) {
      const chip = describeConfidence(label);
      expect(chip.label.length).toBeGreaterThan(0);
      expect(chip.reason.length).toBeGreaterThan(0);
    }
  });

  it.each(CONFIDENCE_LABELS)("%s never renders a raw number, percent or star", (label) => {
    const chip = describeConfidence(label);
    for (const pattern of FORBIDDEN_PATTERNS) {
      expect(chip.label).not.toMatch(pattern);
      expect(chip.reason).not.toMatch(pattern);
    }
  });

  it("an unrecognised label falls back to a neutral, honest chip rather than crashing", () => {
    const chip = describeConfidence("some_future_label_not_yet_known");
    expect(chip.label.length).toBeGreaterThan(0);
    expect(chip.variant).toBe("neutral");
  });

  it("a null/undefined label falls back too", () => {
    expect(describeConfidence(null).label.length).toBeGreaterThan(0);
    expect(describeConfidence(undefined).label.length).toBeGreaterThan(0);
  });

  it("your_previous_choice uses the success (not error/warning) palette", () => {
    expect(describeConfidence("your_previous_choice").variant).toBe("success");
  });
});
