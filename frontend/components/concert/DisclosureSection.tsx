import React, { useState, type ReactNode } from "react";
import { ChevronDown, ChevronRight } from "lucide-react-native";
import { Pressable, Text, View } from "react-native";

import { useTheme } from "@/theme";

export interface DisclosureSectionProps {
  title: string;
  children: ReactNode;
  /** Starts expanded — used when editing a concert that already has content in this section. */
  defaultExpanded?: boolean;
  testID?: string;
}

/**
 * Disclosure trigger — `NewComponents.dc.html` ("collapsed (default)" / "expanded"). AC-3.6: venue,
 * ticket price and doors/start times are collapsed by default — a concert saves with date + one
 * band only.
 */
export function DisclosureSection({
  title,
  children,
  defaultExpanded = false,
  testID,
}: DisclosureSectionProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const [expanded, setExpanded] = useState(defaultExpanded);

  return (
    <View testID={testID} style={{ gap: theme.space("space-3") }}>
      <Pressable
        accessibilityRole="button"
        accessibilityState={{ expanded }}
        accessibilityLabel={title}
        onPress={() => setExpanded((current) => !current)}
        style={{
          flexDirection: "row",
          alignItems: "center",
          gap: theme.space("space-2"),
          minHeight: 44,
        }}
      >
        {expanded ? (
          <ChevronDown size={18} color={colors["text-secondary"]} />
        ) : (
          <ChevronRight size={18} color={colors["text-secondary"]} />
        )}
        <Text
          style={{
            color: colors["text-primary"],
            fontFamily: theme.resolveFontFamily("body", "semibold"),
            fontSize: theme.typeScale.base.fontSize,
            lineHeight: theme.typeScale.base.lineHeight,
          }}
        >
          {title}
        </Text>
      </Pressable>
      {expanded ? <View style={{ gap: theme.space("space-4"), paddingLeft: theme.space("space-6") }}>{children}</View> : null}
    </View>
  );
}
