import React from "react";
import { Text, View } from "react-native";

import { useTheme } from "@/theme";

export type AvatarSize = 28 | 40 | 56 | 72;

export interface AvatarProps {
  /** Initials shown when there is no photo (`Components.dc.html` shows initials-on-gradient). */
  initials: string;
  size?: AvatarSize;
  accessibilityLabel?: string;
  testID?: string;
}

/**
 * Avatar — `Components.dc.html` ("Avatars"): full-radius circle, Petrona initials on the
 * accent-secondary fill. Photo support (the canvas's gradient variant) lands with its first real
 * consumer (a user profile) — this branch renders the initials fallback only.
 */
export function Avatar({
  initials,
  size = 40,
  accessibilityLabel,
  testID,
}: AvatarProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const fontSize = size <= 28 ? 11 : size <= 40 ? 14 : size <= 56 ? 18 : 22;

  return (
    <View
      testID={testID}
      accessibilityRole="image"
      accessibilityLabel={accessibilityLabel ?? initials}
      style={{
        width: size,
        height: size,
        borderRadius: theme.rad("full"),
        backgroundColor: colors["accent-secondary-strong"],
        alignItems: "center",
        justifyContent: "center",
      }}
    >
      <Text
        style={{
          color: colors["surface-raised"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize,
        }}
      >
        {initials}
      </Text>
    </View>
  );
}
