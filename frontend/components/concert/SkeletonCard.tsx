import React from "react";
import { View } from "react-native";

import { useTheme } from "@/theme";

export interface SkeletonCardProps {
  testID?: string;
}

/**
 * Skeleton card — `NewComponents.dc.html`. AC-1.4: rendered while the first page loads, sized to
 * match `ConcertCard` so there is no layout shift once real data arrives.
 */
export function SkeletonCard({ testID }: SkeletonCardProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;

  return (
    <View
      testID={testID}
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
      style={{
        backgroundColor: colors["surface-raised"],
        borderRadius: theme.rad("lg"),
        borderWidth: 1,
        borderColor: colors["border-subtle"],
        padding: theme.space("space-5"),
        gap: theme.space("space-3"),
      }}
    >
      <View style={{ width: 96, height: 12, borderRadius: theme.rad("sm"), backgroundColor: colors["surface-sunken"] }} />
      <View style={{ width: "70%", height: 20, borderRadius: theme.rad("sm"), backgroundColor: colors["surface-sunken"] }} />
      <View style={{ width: "45%", height: 14, borderRadius: theme.rad("sm"), backgroundColor: colors["surface-sunken"] }} />
    </View>
  );
}
