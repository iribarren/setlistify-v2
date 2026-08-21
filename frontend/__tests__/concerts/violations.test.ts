import { mapViolationsToFields, type Violation } from "@/lib/concerts";

describe("lib/concerts/violations (D-36/AC-8.3-AC-8.4)", () => {
  it("maps a plain field path onto its form field", () => {
    const violations: Violation[] = [{ propertyPath: "note", message: "Notes are at most 2000 characters." }];
    const result = mapViolationsToFields(violations);
    expect(result.note).toBe("Notes are at most 2000 characters.");
  });

  it("maps an indexed lineup path onto its band index (AC-8.3)", () => {
    const violations: Violation[] = [
      { propertyPath: "lineup[2].name", message: "Band names are at most 120 characters." },
    ];
    const result = mapViolationsToFields(violations);
    expect(result.bands[2]).toBe("Band names are at most 120 characters.");
  });

  it("routes venue/price sub-paths to their own fields", () => {
    const violations: Violation[] = [
      { propertyPath: "venue.city", message: "City is required with a venue name." },
      { propertyPath: "ticketPrice.currency", message: "Currency must be an ISO 4217 code." },
    ];
    const result = mapViolationsToFields(violations);
    expect(result.venueCity).toBe("City is required with a venue name.");
    expect(result.priceCurrency).toBe("Currency must be an ISO 4217 code.");
  });

  it("AC-8.4: an unrecognised path lands in the form-level summary, not silently dropped", () => {
    const violations: Violation[] = [{ propertyPath: "owner", message: "Unexpected server-side field." }];
    const result = mapViolationsToFields(violations);
    expect(result.formErrors).toEqual(["Unexpected server-side field."]);
  });
});
