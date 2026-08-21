import React from "react";
import { Text, View } from "react-native";

import { useTheme } from "@/theme";

export interface ReservedSectionProps {
  title: string;
  /** e.g. "prompt 19" — shown in small print so the placeholder reads as planned, not broken. */
  comingIn?: string;
  testID?: string;
}

/**
 * Reserved-section placeholder — `NewComponents.dc.html`. AC-5.2: the Playlist, Your note and
 * Share regions on the concert detail screen render this, so prompts 19–21 add real content
 * without a redesign.
 */
export function ReservedSection({ title, comingIn, testID }: ReservedSectionProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;

  return (
    <View
      testID={testID}
      style={{
        borderRadius: theme.rad("lg"),
        borderWidth: 1,
        borderStyle: "dashed",
        borderColor: colors["border-subtle"],
        padding: theme.space("space-5"),
        gap: theme.space("space-1"),
      }}
    >
      <Text
        style={{
          color: colors["text-secondary"],
          fontFamily: theme.resolveFontFamily("body", "semibold"),
          fontSize: theme.typeScale.sm.fontSize,
          lineHeight: theme.typeScale.sm.lineHeight,
        }}
      >
        {title}
      </Text>
      <Text
        style={{
          color: colors["text-tertiary"],
          fontFamily: theme.resolveFontFamily("body", "regular"),
          fontSize: theme.typeScale.xs.fontSize,
          lineHeight: theme.typeScale.xs.lineHeight,
        }}
      >
        Coming later{comingIn ? ` — ${comingIn}` : ""}
      </Text>
    </View>
  );
}
