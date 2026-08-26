import { useEffect } from "react";

import type { PlaybackEmbedProps } from "./PlaybackEmbedTypes";

/**
 * D-216, native half. iOS and Android have no embed surface in this feature — no WebView, no
 * dependency, no native module. Renders nothing and reports unavailability on mount, which is the
 * SAME input (`embedUnavailable`) a blocked web frame produces (D-214/D-215) — so
 * `derivePlaybackSurface()` needs no platform check at all, and `PlaybackPanel` falls through to
 * the deep-link presentation exactly as it would for `onError` (AC-5.8).
 */
export function PlaybackEmbed({ onUnavailable }: PlaybackEmbedProps): null {
  useEffect(() => {
    onUnavailable();
  }, [onUnavailable]);

  return null;
}
