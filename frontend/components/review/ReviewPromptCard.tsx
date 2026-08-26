import React from "react";
import { Text, View } from "react-native";

import { Button, Card } from "@/components";
import { formatConcertDate, type CachedConcert } from "@/lib/concerts";
import { useTheme } from "@/theme";

export interface ReviewPromptCardProps {
  concert: CachedConcert;
  onPress: () => void;
  onDismiss: () => void;
  testID?: string;
}

function lineupSummary(concert: CachedConcert): string {
  const names = [...(concert.lineup ?? [])]
    .sort((a, b) => (a.billingOrder ?? 0) - (b.billingOrder ?? 0))
    .map((entry) => entry.band?.name)
    .filter((name): name is string => Boolean(name));
  return names[0] ?? "that show";
}

/**
 * `ReviewPromptCard` — US-7 (D-242). Rendered at the head of the Past section (AC-7.1), at most
 * one at a time, by `useReviewPromptCard`. AC-7.3: dismissal is permanent for this concert on this
 * device and does not reveal a replacement card in the same sitting.
 */
export function ReviewPromptCard({ concert, onPress, onDismiss, testID }: ReviewPromptCardProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const date = concert.date && concert.timezone ? formatConcertDate(concert.date, concert.timezone) : null;

  return (
    <Card testID={testID}>
      <View style={{ gap: theme.space("space-3") }}>
        <View style={{ gap: theme.space("space-1") }}>
          <Text style={{ color: colors["text-primary"], fontFamily: theme.resolveFontFamily("body", "semibold"), fontSize: theme.typeScale.base.fontSize }}>
            How was {lineupSummary(concert)}?
          </Text>
          <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.sm.fontSize }}>
            {date ? `${date} — s` : "S"}till fresh — write it down before you forget.
          </Text>
        </View>
        <View style={{ flexDirection: "row", gap: theme.space("space-2") }}>
          <Button testID={testID ? `${testID}-write` : undefined} label="Write about it" onPress={onPress} />
          <Button testID={testID ? `${testID}-dismiss` : undefined} label="Not now" variant="secondary" onPress={onDismiss} />
        </View>
      </View>
    </Card>
  );
}
