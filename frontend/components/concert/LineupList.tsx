import React from "react";
import { Text, View } from "react-native";

import { Badge } from "@/components";
import type { LineupEntryOutput } from "@/lib/concerts";
import { useTheme } from "@/theme";

export interface LineupListProps {
  lineup: LineupEntryOutput[];
  testID?: string;
}

function ordinal(n: number): string {
  const j = n % 10;
  const k = n % 100;
  if (j === 1 && k !== 11) return `${n}st`;
  if (j === 2 && k !== 12) return `${n}nd`;
  if (j === 3 && k !== 13) return `${n}rd`;
  return `${n}th`;
}

/**
 * `ConcertDetail.dc.html` / `NewComponents.dc.html` ("Band entry row"). AC-5.1: lineup with the
 * headliner labelled — billing order 0 gets a "Headliner" badge, every later entry an ordinal
 * badge (2nd, 3rd, …) rather than a plain unlabelled list.
 */
export function LineupList({ lineup, testID }: LineupListProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const sorted = [...lineup].sort((a, b) => (a.billingOrder ?? 0) - (b.billingOrder ?? 0));

  return (
    <View testID={testID} style={{ gap: theme.space("space-3") }}>
      {sorted.map((entry, index) => (
        <View
          key={entry.band?.id ?? `${entry.band?.name}-${index}`}
          style={{ flexDirection: "row", alignItems: "center", justifyContent: "space-between" }}
        >
          <Text
            style={{
              color: colors["text-primary"],
              fontFamily: theme.resolveFontFamily("body", index === 0 ? "semibold" : "regular"),
              fontSize: theme.typeScale.base.fontSize,
              lineHeight: theme.typeScale.base.lineHeight,
            }}
          >
            {entry.band?.name ?? "Unknown band"}
          </Text>
          <Badge label={index === 0 ? "Headliner" : ordinal(index + 1)} variant={index === 0 ? "info" : "neutral"} />
        </View>
      ))}
    </View>
  );
}
