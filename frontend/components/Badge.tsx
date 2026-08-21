import React from "react";
import { Text, View } from "react-native";

import { useTheme } from "@/theme";

export type BadgeVariant = "success" | "warning" | "error" | "info" | "neutral";

export interface BadgeProps {
  label: string;
  variant?: BadgeVariant;
  testID?: string;
}

/**
 * Badge — `Components.dc.html` ("Badges"): a soft fill tint, a dot, and the `-strong` text color
 * for its hue so it always clears AA on its own fill (not just on the page background).
 */
export function Badge({ label, variant = "neutral", testID }: BadgeProps): React.JSX.Element {
  const theme = useTheme();
  const { colors, scheme } = theme;
  const { fill, dot, text } = badgePalette(variant, colors, scheme);

  return (
    <View
      testID={testID}
      accessibilityRole="text"
      style={{
        flexDirection: "row",
        alignItems: "center",
        gap: theme.space("space-2"),
        minHeight: 28,
        paddingHorizontal: theme.space("space-3"),
        borderRadius: theme.rad("full"),
        backgroundColor: fill,
        alignSelf: "flex-start",
      }}
    >
      <View style={{ width: 6, height: 6, borderRadius: theme.rad("full"), backgroundColor: dot }} />
      <Text
        style={{
          color: text,
          fontFamily: theme.resolveFontFamily("body", "bold"),
          fontSize: theme.typeScale.xs.fontSize,
          lineHeight: theme.typeScale.xs.lineHeight,
        }}
      >
        {label}
      </Text>
    </View>
  );
}

// The canvas (Components.dc.html) shows each badge on a soft hue-tinted fill (e.g. `#e2f2e3` for
// success) that is not one of the enumerated AC-2.1 tokens. Rather than transcribing a second,
// untracked set of fill hexes (which AC-2.5 forbids as component-level literals), the fill is
// derived from the existing `-bright` token at low alpha — same hue family, zero new tokens, and
// it degrades sensibly on any surface including dark mode.
const FILL_ALPHA = "26"; // ~15%

function badgePalette(
  variant: BadgeVariant,
  colors: ReturnType<typeof useTheme>["colors"],
  scheme: "light" | "dark",
): { fill: string; dot: string; text: string } {
  if (variant === "neutral") {
    return {
      fill: colors["surface-sunken"],
      dot: colors["text-tertiary"],
      text: scheme === "dark" ? colors["text-secondary"] : colors["text-tertiary"],
    };
  }

  const strong = colors[`${variant}-strong`];
  const bright = colors[`${variant}-bright`];
  return { fill: `${bright}${FILL_ALPHA}`, dot: bright, text: strong };
}
