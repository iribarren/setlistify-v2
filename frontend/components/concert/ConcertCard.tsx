import React, { useState } from "react";
import { Pressable, Text, View } from "react-native";

import { Badge } from "@/components";
import { formatConcertDate, type CachedConcert } from "@/lib/concerts";
import { StarRating } from "@/components/review";
import { useTheme } from "@/theme";

export interface ConcertCardProps {
  concert: CachedConcert;
  onPress?: () => void;
  testID?: string;
}

function lineupSummary(concert: CachedConcert): string {
  const names = [...(concert.lineup ?? [])]
    .sort((a, b) => (a.billingOrder ?? 0) - (b.billingOrder ?? 0))
    .map((entry) => entry.band?.name)
    .filter((name): name is string => Boolean(name));

  if (names.length === 0) {
    return "Lineup to be added";
  }
  if (names.length === 1) {
    return names[0];
  }
  if (names.length === 2) {
    return `${names[0]} + ${names[1]}`;
  }
  return `${names[0]} + ${names.length - 1} more`;
}

function venueSummary(concert: CachedConcert): string | null {
  const parts = [concert.venue?.name, concert.venue?.city].filter(Boolean);
  return parts.length > 0 ? parts.join(", ") : null;
}

/**
 * Concert card — `Main.dc.html`. AC-1.3: lineup in billing order with the headliner visually
 * primary, date in the user's locale, venue when present. AC-4.5: a pending (optimistic, D-33)
 * card is visually marked and not tappable through to a detail screen — it has no server id yet.
 */
export function ConcertCard({ concert, onPress, testID }: ConcertCardProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const [pressed, setPressed] = useState(false);
  const pending = Boolean(concert.__pending);
  const venue = venueSummary(concert);

  const content = (
    <View
      style={{
        backgroundColor: pressed ? colors["surface-sunken"] : colors["surface-raised"],
        borderRadius: theme.rad("lg"),
        borderWidth: 1,
        borderColor: colors["border-subtle"],
        padding: theme.space("space-5"),
        gap: theme.space("space-2"),
        opacity: pending ? 0.6 : 1,
      }}
    >
      <View style={{ flexDirection: "row", alignItems: "center", justifyContent: "space-between" }}>
        <Text
          style={{
            color: colors["text-tertiary"],
            fontFamily: theme.resolveFontFamily("mono", "medium"),
            fontSize: theme.typeScale.xs.fontSize,
            lineHeight: theme.typeScale.xs.lineHeight,
          }}
        >
          {concert.date && concert.timezone
            ? formatConcertDate(concert.date, concert.timezone, undefined, {
                day: "numeric",
                month: "short",
                year: "numeric",
              })
            : ""}
        </Text>
        {pending ? <Badge label="Saving…" variant="info" /> : null}
      </View>
      <Text
        style={{
          color: colors["text-primary"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize: theme.typeScale.lg.fontSize,
          lineHeight: theme.typeScale.lg.lineHeight,
        }}
      >
        {lineupSummary(concert)}
      </Text>
      {venue ? (
        <Text
          style={{
            color: colors["text-tertiary"],
            fontFamily: theme.resolveFontFamily("body", "regular"),
            fontSize: theme.typeScale.sm.fontSize,
            lineHeight: theme.typeScale.sm.lineHeight,
          }}
        >
          {venue}
        </Text>
      ) : null}
      {/* AC-6.3/AC-6.4: only a PAST concert ever shows a review indicator, and only when one
          exists — absence is the signal, so an unreviewed past concert renders nothing here. */}
      {concert.status === "past" && concert.reviewSummary ? (
        <View testID={testID ? `${testID}-review-indicator` : undefined}>
          {concert.reviewSummary.rating != null ? (
            <StarRating value={concert.reviewSummary.rating} size={14} />
          ) : (
            <Badge label="Written up" variant="neutral" />
          )}
        </View>
      ) : null}
    </View>
  );

  if (pending || !onPress) {
    return (
      <View testID={testID} accessibilityLabel={pending ? "Saving concert" : undefined}>
        {content}
      </View>
    );
  }

  return (
    <Pressable
      testID={testID}
      onPress={onPress}
      onPressIn={() => setPressed(true)}
      onPressOut={() => setPressed(false)}
      accessibilityRole="button"
      accessibilityLabel={`${lineupSummary(concert)}${venue ? `, ${venue}` : ""}`}
    >
      {content}
    </Pressable>
  );
}
