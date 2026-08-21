import React, { type ReactNode } from "react";
import { View } from "react-native";

import { useTheme } from "@/theme";

export interface CardProps {
  children: ReactNode;
  testID?: string;
}

/**
 * Generic card — `Cards.dc.html` ("Generic card — setlist card"): radius-lg, elevation-1 by
 * default. AC-3.4: in dark mode a flat card defaults to elevation-0 and leans on `border-strong`
 * instead, since a shadow reads as nothing against near-black.
 */
export function Card({ children, testID }: CardProps): React.JSX.Element {
  const theme = useTheme();
  const { colors, scheme } = theme;
  const elevationLevel = scheme === "dark" ? 0 : 1;

  return (
    <View
      testID={testID}
      style={[
        {
          backgroundColor: colors["surface-raised"],
          borderRadius: theme.rad("lg"),
          padding: theme.space("space-5"),
          borderWidth: 1,
          borderColor: scheme === "dark" ? colors["border-strong"] : colors["border-subtle"],
        },
        theme.getElevationStyle(elevationLevel),
      ]}
    >
      {children}
    </View>
  );
}
