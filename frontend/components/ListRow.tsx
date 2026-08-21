import React, { useState, type ReactNode } from "react";
import { Pressable, Text, View } from "react-native";

import { useTheme } from "@/theme";

export interface ListRowProps {
  title: string;
  subtitle?: string;
  /** Rendered at the row's leading edge (e.g. a track index or an icon). */
  leading?: ReactNode;
  /** Rendered at the trailing edge (e.g. a matched/unmatched status glyph). */
  trailing?: ReactNode;
  onPress?: () => void;
  accessibilityLabel?: string;
  testID?: string;
}

/**
 * List row — `Cards.dc.html` ("List row — setlist song"): elevation-0, divider only. Hover is a
 * background tint (`surface-sunken`), never a shadow; on touch there is only pressed, same tint, no
 * hover state. 44px minimum height even where content is shorter (AC-4.3).
 */
export function ListRow({
  title,
  subtitle,
  leading,
  trailing,
  onPress,
  accessibilityLabel,
  testID,
}: ListRowProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const [pressed, setPressed] = useState(false);

  const content = (
    <View
      style={{
        flexDirection: "row",
        alignItems: "center",
        gap: theme.space("space-3"),
        paddingVertical: theme.space("space-3"),
        paddingHorizontal: theme.space("space-4"),
        minHeight: 44,
        backgroundColor: pressed ? colors["surface-sunken"] : "transparent",
        borderBottomWidth: 1,
        borderBottomColor: colors["border-subtle"],
      }}
    >
      {leading}
      <View style={{ flex: 1 }}>
        <Text
          style={{
            color: colors["text-primary"],
            fontFamily: theme.resolveFontFamily("body", "medium"),
            fontSize: theme.typeScale.base.fontSize,
            lineHeight: theme.typeScale.base.lineHeight,
          }}
        >
          {title}
        </Text>
        {subtitle ? (
          <Text
            style={{
              color: colors["text-tertiary"],
              fontFamily: theme.resolveFontFamily("body", "regular"),
              fontSize: theme.typeScale.sm.fontSize,
              lineHeight: theme.typeScale.sm.lineHeight,
            }}
          >
            {subtitle}
          </Text>
        ) : null}
      </View>
      {trailing}
    </View>
  );

  if (!onPress) {
    return content;
  }

  return (
    <Pressable
      testID={testID}
      onPress={onPress}
      onPressIn={() => setPressed(true)}
      onPressOut={() => setPressed(false)}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? [title, subtitle].filter(Boolean).join(", ")}
    >
      {content}
    </Pressable>
  );
}
