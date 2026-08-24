import { chooseProvider, selectProviderCandidates } from "@/lib/playlist";
import type { ProviderConfigOutput } from "@/lib/playlist";

function provider(overrides: Partial<ProviderConfigOutput>): ProviderConfigOutput {
  return { "@id": `/api/config/providers/${overrides.key}`, "@type": "ProviderConfig", ...overrides };
}

const providers: ProviderConfigOutput[] = [
  provider({ key: "spotify", displayName: "Spotify", enabled: true, isDefault: false }),
  provider({ key: "youtube", displayName: "YouTube", enabled: true, isDefault: false }),
  provider({ key: "apple", displayName: "Apple Music", enabled: false, isDefault: false }),
];

describe("providerChoice (T-4, D-169)", () => {
  it("0 candidates: no connected account at all", () => {
    const candidates = selectProviderCandidates(providers, []);
    expect(candidates).toEqual([]);
    expect(chooseProvider(candidates).kind).toBe("none");
  });

  it("excludes a disabled provider even with a connected account", () => {
    const candidates = selectProviderCandidates(providers, [{ provider: "apple", status: "connected" }]);
    expect(candidates).toEqual([]);
  });

  it("excludes an enabled provider with only a non-connected account", () => {
    const candidates = selectProviderCandidates(providers, [{ provider: "spotify", status: "needs_reauth" }]);
    expect(candidates).toEqual([]);
  });

  it("1 candidate → used silently, no chooser", () => {
    const candidates = selectProviderCandidates(providers, [{ provider: "spotify", status: "connected" }]);
    const choice = chooseProvider(candidates);
    expect(choice).toEqual({ kind: "single", provider: expect.objectContaining({ key: "spotify" }) });
  });

  it("many candidates with a default → used silently, alternatives listed", () => {
    const withDefault: ProviderConfigOutput[] = [
      provider({ key: "spotify", displayName: "Spotify", enabled: true, isDefault: true }),
      provider({ key: "youtube", displayName: "YouTube", enabled: true, isDefault: false }),
    ];
    const candidates = selectProviderCandidates(withDefault, [
      { provider: "spotify", status: "connected" },
      { provider: "youtube", status: "connected" },
    ]);
    const choice = chooseProvider(candidates);
    expect(choice.kind).toBe("default");
    if (choice.kind === "default") {
      expect(choice.provider.key).toBe("spotify");
      expect(choice.alternatives.map((c) => c.key)).toEqual(["youtube"]);
    }
  });

  it("many candidates with no default (spec 11 AC-7.4) → the user is asked", () => {
    const candidates = selectProviderCandidates(providers, [
      { provider: "spotify", status: "connected" },
      { provider: "youtube", status: "connected" },
    ]);
    const choice = chooseProvider(candidates);
    expect(choice.kind).toBe("choice");
    if (choice.kind === "choice") {
      expect(choice.candidates).toHaveLength(2);
    }
  });
});
