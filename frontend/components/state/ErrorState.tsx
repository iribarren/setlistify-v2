import React from "react";
import { TriangleAlert } from "lucide-react-native";
import { Text, View } from "react-native";

import { useTheme } from "@/theme";

import { StateAction, type StateActionSpec } from "./StateAction";

export interface ErrorStateProps {
  title: string;
  body: string;
  /** AC-5.4: `ErrorState` always offers a retry action. */
  action: StateActionSpec;
  testID?: string;
}

/**
 * `States.dc.html` ("Error") — the warning-triangle + error-red treatment belongs to Error alone;
 * `DegradedState` deliberately never uses it (AC-5.3, R-6). The icon/title/body are grouped into
 * one `accessibilityRole="alert"` announcement; the retry action sits outside that group so it
 * stays individually reachable by assistive technology rather than being swallowed into the alert.
 */
export function ErrorState({ title, body, action, testID }: ErrorStateProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;

  return (
    <View
      testID={testID}
      style={{
        alignItems: "center",
        padding: theme.space("space-8"),
        gap: theme.space("space-4"),
      }}
    >
      <View
        accessible
        accessibilityRole="alert"
        accessibilityLabel={`${title}. ${body}`}
        style={{ alignItems: "center", gap: theme.space("space-4") }}
      >
        <TriangleAlert size={32} color={colors["error-strong"]} strokeWidth={1.5} />
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
      </View>
      <StateAction {...action} />
    </View>
  );
}
