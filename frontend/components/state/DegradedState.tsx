import React from "react";
import { Text, View } from "react-native";

import { useTheme } from "@/theme";

import { StateAction, type StateActionSpec } from "./StateAction";

export interface DegradedProgress {
  completed: number;
  total: number;
}

export interface DegradedStateProps {
  title: string;
  body: string;
  progress: DegradedProgress;
  action?: StateActionSpec;
  testID?: string;
}

/**
 * `States.dc.html` ("Degraded") — AC-5.3: visually distinct from `ErrorState` by construction, not
 * convention. A progress fraction + an info-blue fill, deliberately NO warning triangle and NO
 * amber/red treatment: "14 of 19 matched" is a successful run, not a failure (R-6).
 */
export function DegradedState({
  title,
  body,
  progress,
  action,
  testID,
}: DegradedStateProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const fraction = progress.total > 0 ? progress.completed / progress.total : 0;

  return (
    <View
      testID={testID}
      style={{
        alignItems: "center",
        padding: theme.space("space-8"),
        gap: theme.space("space-3"),
      }}
    >
      <Text
        testID={testID ? `${testID}-count` : undefined}
        style={{
          color: colors["info-strong"],
          fontFamily: theme.resolveFontFamily("mono", "bold"),
          fontSize: theme.typeScale.xl.fontSize,
          lineHeight: theme.typeScale.xl.lineHeight,
        }}
      >
        {progress.completed} / {progress.total}
      </Text>
      <View
        accessible
        accessibilityRole="progressbar"
        accessibilityValue={{ min: 0, max: progress.total, now: progress.completed }}
        style={{
          width: "100%",
          maxWidth: 240,
          height: 8,
          borderRadius: theme.rad("full"),
          backgroundColor: colors["surface-sunken"],
          overflow: "hidden",
        }}
      >
        <View
          style={{
            width: `${Math.round(fraction * 100)}%`,
            height: "100%",
            backgroundColor: colors["info-bright"],
          }}
        />
      </View>
      <Text
        style={{
          color: colors["text-primary"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize: theme.typeScale.lg.fontSize,
          lineHeight: theme.typeScale.lg.lineHeight,
          textAlign: "center",
          marginTop: theme.space("space-2"),
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
      {action ? <StateAction {...action} /> : null}
    </View>
  );
}
