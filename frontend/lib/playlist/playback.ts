import { asPlaybackMode, type PlaylistOutput, type ProviderConfigOutput } from "./types";

/**
 * D-215: a blocked cross-origin frame fires no `onerror` reliably — without a timeout, "never an
 * empty region" (AC-5.5) is unenforceable. A copy-style choice, not a measurement (slower than any
 * healthy embed, faster than a user's patience with a blank box) — one named constant, cheap to
 * change.
 */
export const EMBED_LOAD_TIMEOUT_MS = 8000;

/**
 * D-213: what `PlaybackPanel` renders. `off` has no variant of its own — it, like every unrecognised
 * or not-yet-loaded state, resolves to `"metadata"` (deny by default, AC-4.5).
 */
export type PlaybackSurface =
  | { kind: "embed"; embedUrl: string }
  | { kind: "deeplink"; url: string }
  | { kind: "metadata" };

export interface DerivePlaybackSurfaceInput {
  /** `undefined` while `GET /api/config/providers` is loading or has failed (AC-4.5). */
  provider: ProviderConfigOutput | undefined;
  playlist: PlaylistOutput;
  /**
   * True when THIS client cannot show an embed for this playlist right now: the frame errored
   * (D-215), the watchdog expired (D-215), or the platform has no embed surface at all (D-216,
   * native's `PlaybackEmbed.native` reports this on mount). One input, one meaning — "no embed here,
   * now" — so nothing downstream branches on a platform.
   */
  embedUnavailable: boolean;
}

function deeplinkOrMetadata(externalUrl: string | null | undefined): PlaybackSurface {
  return externalUrl ? { kind: "deeplink", url: externalUrl } : { kind: "metadata" };
}

/**
 * D-213: the ONLY place in `frontend/` that reads `.playbackMode` (AC-7.3, enforced by a static
 * test). Pure — the full truth table (spec 19 §3) is exhaustively unit-tested without mounting
 * anything.
 */
export function derivePlaybackSurface({
  provider,
  playlist,
  embedUnavailable,
}: DerivePlaybackSurfaceInput): PlaybackSurface {
  const mode = asPlaybackMode(provider?.playbackMode);
  const externalUrl = playlist.externalUrl ?? null;
  const embedUrl = playlist.embedUrl ?? null;

  // Config missing/loading/failed, or an unrecognised playbackMode: deny by default (AC-4.5).
  if (!provider || !mode) {
    return { kind: "metadata" };
  }

  if (mode === "off") {
    return { kind: "metadata" };
  }

  if (mode === "deeplink") {
    return deeplinkOrMetadata(externalUrl);
  }

  // mode === "embed"
  // D-219: a disabled provider degrades embed -> deeplink, never to off. `enabled` gates OUR
  // integration, not the user's own already-created playlist.
  if (!provider.enabled) {
    return deeplinkOrMetadata(externalUrl);
  }

  // AC-5.1: the provider has no embed at all, or the playlist has no provider-side id yet.
  if (!embedUrl) {
    return deeplinkOrMetadata(externalUrl);
  }

  // AC-5.2/AC-5.3/AC-5.8: the frame errored, the watchdog expired, or the platform has none.
  if (embedUnavailable) {
    return deeplinkOrMetadata(externalUrl);
  }

  return { kind: "embed", embedUrl };
}
