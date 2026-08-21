import React from "react";
import { Inbox } from "lucide-react-native";
import { Text, View } from "react-native";

import { useTheme } from "@/theme";

import { StateAction, type StateActionSpec } from "./StateAction";

export interface EmptyStateProps {
  title: string;
  body: string;
  action?: StateActionSpec;
  testID?: string;
}

/** `States.dc.html` ("Empty") — a normal outcome (e.g. no concerts tracked yet), not an error. */
export function EmptyState({ title, body, action, testID }: EmptyStateProps): React.JSX.Element {
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
      <Inbox size={32} color={colors["text-tertiary"]} strokeWidth={1.25} />
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
      {action ? <StateAction {...action} /> : null}
    </View>
  );
}
