import { extractRef } from "@/lib/streaming/linkAccount.native";

describe("linkAccount.native extractRef", () => {
  it("reads the opaque ref query param from the deep-link return URL (AC-1.8)", () => {
    expect(extractRef("setlistify://account?ref=abc123")).toBe("abc123");
  });

  it("returns null when the return URL carries no ref", () => {
    expect(extractRef("setlistify://account")).toBeNull();
  });

  it("returns null rather than throwing for an unparsable URL", () => {
    expect(extractRef("not a url")).toBeNull();
  });

  it("never carries a token, code or verifier query param through this parsing (D-74)", () => {
    // The backend's return leg only ever includes `ref` (AC-1.7/AC-1.8) — this just documents that
    // extraction reads exactly that key and nothing else, even if a URL somehow carried more.
    const ref = extractRef("setlistify://account?ref=abc123&access_token=leaked");
    expect(ref).toBe("abc123");
  });
});
