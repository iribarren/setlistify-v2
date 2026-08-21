import React from "react";
import { Pressable, Text } from "react-native";

import { useTheme } from "@/theme";

export interface StateActionSpec {
  label: string;
  onPress: () => void;
}

/**
 * The shared CTA rendered by every state component — `States.dc.html` renders it as a plain text
 * link (e.g. "+ Track a concert", "Try again"), not full button chrome.
 */
export function StateAction({ label, onPress }: StateActionSpec): React.JSX.Element {
  const theme = useTheme();

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={label}
      // AC-4.3: pad the tap area rather than shrinking the control to fit the text link's size.
      hitSlop={{ top: 12, bottom: 12, left: 12, right: 12 }}
      style={{ minHeight: 44, justifyContent: "center" }}
    >
      <Text
        style={{
          color: theme.colors["accent-primary-strong"],
          fontFamily: theme.resolveFontFamily("body", "semibold"),
          fontSize: theme.typeScale.sm.fontSize,
          lineHeight: theme.typeScale.sm.lineHeight,
        }}
      >
        {label}
      </Text>
    </Pressable>
  );
}
