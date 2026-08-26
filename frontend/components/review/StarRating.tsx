import React from "react";
import { Star } from "lucide-react-native";
import { Pressable, Text, View } from "react-native";

import { useTheme } from "@/theme";

const STAR_COUNT = 5;

export interface StarRatingProps {
  /** `null` — no rating yet (D-230/D-231: a review may be notes-only). */
  value: number | null;
  /** Omit for a read-only display (e.g. the list indicator, `ConcertCard`). */
  onChange?: (rating: number) => void;
  size?: number;
  testID?: string;
}

/**
 * `StarRating` — D-230: five discrete 1–5 targets, no half stars. AC-4.3/US-2: each target has an
 * accessible "Rate N out of 5 stars" label so the control reads sensibly on a screen reader, in
 * both read-only mode (`ConcertCard`'s indicator, a written review's display) and interactive mode
 * (`ReviewEditor`).
 */
export function StarRating({ value, onChange, size = 24, testID }: StarRatingProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const interactive = Boolean(onChange);
  const stars = Array.from({ length: STAR_COUNT }, (_, index) => index + 1);

  return (
    <View
      testID={testID}
      accessibilityRole={interactive ? undefined : "text"}
      accessibilityLabel={interactive ? undefined : value ? `Rated ${value} out of 5 stars` : "Not rated"}
      style={{ flexDirection: "row", gap: theme.space("space-1") }}
    >
      {stars.map((star) => {
        const filled = value != null && star <= value;
        const color = filled ? colors["warning-bright"] : colors["border-strong"];

        if (!interactive) {
          return <Star key={star} size={size} color={color} fill={filled ? color : "transparent"} />;
        }

        return (
          <Pressable
            key={star}
            testID={testID ? `${testID}-star-${star}` : undefined}
            onPress={() => onChange?.(star)}
            accessibilityRole="radio"
            accessibilityState={{ checked: value === star }}
            accessibilityLabel={`Rate ${star} out of 5 stars`}
            style={{ minWidth: 44, minHeight: 44, alignItems: "center", justifyContent: "center" }}
          >
            <Star size={size} color={color} fill={filled ? color : "transparent"} />
          </Pressable>
        );
      })}
      {!interactive && value == null ? (
        <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>Not rated</Text>
      ) : null}
    </View>
  );
}
