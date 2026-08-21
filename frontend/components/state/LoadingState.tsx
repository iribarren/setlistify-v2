import React from "react";
import { ActivityIndicator, Text, View } from "react-native";

import { useTheme } from "@/theme";

export interface LoadingStateProps {
  title: string;
  body?: string;
  testID?: string;
}

/**
 * `States.dc.html` ("Loading"). AC-5.5: announced to assistive technology via
 * `accessibilityLiveRegion` (native) / `aria-live` (web, applied automatically by RN Web from the
 * same prop), so a screen reader user learns something is in flight without polling.
 */
export function LoadingState({ title, body, testID }: LoadingStateProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;

  return (
    <View
      testID={testID}
      accessible
      accessibilityRole="progressbar"
      accessibilityLiveRegion="polite"
      accessibilityLabel={title}
      style={{
        alignItems: "center",
        padding: theme.space("space-8"),
        gap: theme.space("space-4"),
      }}
    >
      <ActivityIndicator size="large" color={colors["accent-primary-strong"]} />
      <Text
        style={{
          color: colors["text-primary"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize: theme.typeScale.lg.fontSize,
          lineHeight: theme.typeScale.lg.lineHeight,
          textAlign: "center",
        }}
      >
        {title}
      </Text>
      {body ? (
        <Text
          style={{
            color: colors["text-tertiary"],
            fontFamily: theme.resolveFontFamily("body", "regular"),
            fontSize: theme.typeScale.sm.fontSize,
            lineHeight: theme.typeScale.sm.lineHeight,
            textAlign: "center",
          }}
        >
          {body}
        </Text>
      ) : null}
    </View>
  );
}
