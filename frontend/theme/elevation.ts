import { Platform, type ViewStyle } from "react-native";

/**
 * Elevation tokens transcribed from `docs/design/canvas/SpacingElevation.dc.html` ("Elevation").
 * Single-layer, low-blur shadows only — this is what survives react-native-web unchanged: it maps
 * directly to `shadowColor/shadowOffset/shadowOpacity/shadowRadius` on iOS and web, and `elevation`
 * on Android, with no divergent style branch.
 *
 * AC-3.4: flat surfaces default to `elevation-0` in dark mode, where a shadow reads as nothing
 * against near-black — callers should pass `0` and lean on the `border-strong` token instead. That
 * per-surface choice is made by the component (e.g. `Card`), not baked into this file.
 */
export type ElevationToken = 0 | 1 | 2 | 3;

// Shadow color is the canvas's warm near-black ink (#221812), not pure black, on every platform.
const SHADOW_COLOR = "#221812";

interface ElevationSpec {
  offsetHeight: number;
  opacity: number;
  radius: number;
  androidElevation: number;
}

const specs: Record<ElevationToken, ElevationSpec | null> = {
  0: null, // border only, no shadow
  1: { offsetHeight: 1, opacity: 0.08, radius: 2, androidElevation: 2 }, // card, list row on hover
  2: { offsetHeight: 4, opacity: 0.12, radius: 10, androidElevation: 6 }, // dropdown, toast
  3: { offsetHeight: 12, opacity: 0.18, radius: 28, androidElevation: 12 }, // modal, bottom sheet
};

export function getElevationStyle(level: ElevationToken): ViewStyle {
  const spec = specs[level];
  if (!spec) {
    return {};
  }

  if (Platform.OS === "android") {
    return { elevation: spec.androidElevation };
  }

  // iOS and web both honor the shadow* properties (react-native-web translates them to
  // `box-shadow`), so one branch covers both.
  return {
    shadowColor: SHADOW_COLOR,
    shadowOffset: { width: 0, height: spec.offsetHeight },
    shadowOpacity: spec.opacity,
    shadowRadius: spec.radius,
  };
}
