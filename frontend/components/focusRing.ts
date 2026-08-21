import { Platform, type ViewStyle } from "react-native";

import type { Theme } from "@/theme";

/**
 * Focus ring shared by every interactive component (AC-4.4): a 3px `accent-primary` ring at 15%
 * opacity (Components.dc.html) plus a 1.5px solid `accent-primary` edge on the control itself
 * (Accessibility.dc.html) — the ring's geometry is what registers, so it is never conveyed by color
 * alone. Keyboard and touch/mouse focus share the same treatment; there is no keyboard-only
 * variant to fall out of sync.
 *
 * react-native-web maps CSS `outline-*` directly, which is the only way to draw a ring OUTSIDE a
 * control's box without an extra wrapper view. Native platforms fall back to the solid edge alone
 * (RN has no outline concept) — still visible, never color-only, since the border width itself
 * changes.
 */
export function focusRingStyle(theme: Theme): ViewStyle {
  const ringColor = theme.colors["accent-primary-strong"];
  if (Platform.OS === "web") {
    return {
      // react-native-web-only style properties (react-native-web augments RN's ViewStyle type
      // with these when installed) — ignored on native, where the solid edge below still applies.
      outlineWidth: 3,
      outlineColor: `${ringColor}26`, // ~15% opacity
      outlineStyle: "solid",
      outlineOffset: 1,
      borderColor: ringColor,
    };
  }
  return {
    borderColor: ringColor,
  };
}
