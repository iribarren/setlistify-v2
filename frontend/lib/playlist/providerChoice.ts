import type { ProviderConfigOutput } from "./types";

/**
 * D-169. Every provider-specific string here is read off the server (`displayName`) — no provider
 * key literal appears anywhere in this file (AC-1.4, T-16).
 */
export interface ProviderCandidate {
  key: string;
  displayName: string;
  isDefault: boolean;
}

export interface ConnectedAccountLike {
  provider?: string | null;
  status?: string | null;
}

/**
 * `candidates = providers.filter(enabled) ∩ accounts.filter(status = connected)` — the exact set
 * from D-169. Order follows `providers` (server-driven; `GET /api/config/providers`'s own order).
 */
export function selectProviderCandidates(
  providers: ProviderConfigOutput[] | undefined,
  accounts: ConnectedAccountLike[] | undefined,
): ProviderCandidate[] {
  const connectedKeys = new Set(
    (accounts ?? [])
      .filter((account) => account.status === "connected" && account.provider)
      .map((account) => account.provider as string),
  );

  return (providers ?? [])
    .filter((provider) => provider.enabled && provider.key && connectedKeys.has(provider.key))
    .map((provider) => ({
      key: provider.key as string,
      displayName: provider.displayName ?? (provider.key as string),
      isDefault: provider.isDefault ?? false,
    }));
}

export type ProviderChoice =
  /** AC-1.3: no connected+enabled provider — the trigger becomes a link-an-account prompt. */
  | { kind: "none" }
  /** Exactly one candidate — used silently, no chooser (AC-1.3). */
  | { kind: "single"; provider: ProviderCandidate }
  /** >1 candidates, one is the default — used silently; the rest are available as alternatives. */
  | { kind: "default"; provider: ProviderCandidate; alternatives: ProviderCandidate[] }
  /** >1 candidates, no default (a valid state, spec 11 AC-7.4) — the user is asked before starting. */
  | { kind: "choice"; candidates: ProviderCandidate[] };

/** T-4: 0 / 1 / many-with-default / many-without-default. */
export function chooseProvider(candidates: ProviderCandidate[]): ProviderChoice {
  if (candidates.length === 0) {
    return { kind: "none" };
  }
  if (candidates.length === 1) {
    return { kind: "single", provider: candidates[0] };
  }
  const defaultProvider = candidates.find((candidate) => candidate.isDefault);
  if (defaultProvider) {
    return {
      kind: "default",
      provider: defaultProvider,
      alternatives: candidates.filter((candidate) => candidate.key !== defaultProvider.key),
    };
  }
  return { kind: "choice", candidates };
}

/** D-175: the "Use <other provider>" alternative offered inline on `DegradedProviderDisabled`. */
export function alternativeProviderFor(
  disabledProviderKey: string | null | undefined,
  providers: ProviderConfigOutput[] | undefined,
  accounts: ConnectedAccountLike[] | undefined,
): ProviderCandidate | null {
  const candidates = selectProviderCandidates(providers, accounts);
  return candidates.find((candidate) => candidate.key !== disabledProviderKey) ?? null;
}
