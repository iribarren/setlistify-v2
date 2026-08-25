import React, { useState } from "react";
import { Text, View } from "react-native";

import { Button, Card } from "@/components";
import type { PlaylistGenerationJobOutput } from "@/lib/playlist";
import { asJobState } from "@/lib/playlist";
import { useTheme } from "@/theme";

export interface ResumeBannerProps {
  job: PlaylistGenerationJobOutput;
  onResume: () => void;
  onStartOver: () => void;
  startingOver: boolean;
  testID?: string;
}

/**
 * `Resume.dc.html` (D-207/AC-3.2-AC-3.4). Rendered by `PlaylistSection` IN PLACE OF the generate
 * trigger whenever a non-terminal `awaiting_*` job exists for the concert — no inbox, no
 * notification, reopening the concert IS the re-entry path. Info-family styling throughout (spec 16's
 * D-168 colour discipline): this is progress paused, not a problem. "Start over" is visually demoted
 * and requires an inline confirmation naming what's discarded (D-208) before it fires.
 */
export function ResumeBanner({ job, onResume, onStartOver, startingOver, testID }: ResumeBannerProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const [confirmingStartOver, setConfirmingStartOver] = useState(false);
  const state = asJobState(job.state);

  const made = job.choicesMadeCount ?? null;
  const required = job.choicesRequiredCount ?? null;

  const headline =
    state === "awaiting_setlist_choice"
      ? "Pick up where you left off"
      : made != null && required != null
        ? `${made} of ${required} songs are already decided`
        : "A few songs need a quick look";

  const body =
    state === "awaiting_setlist_choice"
      ? "You started choosing the setlist for this playlist — pick up right where you left off."
      : "You're most of the way through confirming this playlist's uncertain songs.";

  return (
    <Card testID={testID}>
      <View style={{ gap: theme.space("space-3") }}>
        <Text
          testID={testID ? `${testID}-headline` : undefined}
          style={{ color: colors["info-strong"], fontFamily: theme.resolveFontFamily("display", "semibold"), fontSize: theme.typeScale.base.fontSize }}
        >
          {headline}
        </Text>
        <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.sm.fontSize, lineHeight: theme.typeScale.sm.lineHeight }}>{body}</Text>

        {confirmingStartOver ? (
          <View style={{ gap: theme.space("space-3") }}>
            <Text style={{ color: colors["text-secondary"], fontSize: theme.typeScale.sm.fontSize }}>
              Starting over discards
              {made != null && required != null ? ` the ${made} of ${required} songs you've already decided` : " everything you've chosen so far"}
              . This can&apos;t be undone.
            </Text>
            <View style={{ flexDirection: "row", gap: theme.space("space-2") }}>
              <Button
                testID={testID ? `${testID}-start-over-confirm` : undefined}
                label={startingOver ? "Starting over…" : "Yes, start over"}
                variant="destructive"
                onPress={onStartOver}
                disabled={startingOver}
              />
              <Button
                testID={testID ? `${testID}-start-over-cancel` : undefined}
                label="Keep my progress"
                variant="secondary"
                onPress={() => setConfirmingStartOver(false)}
                disabled={startingOver}
              />
            </View>
          </View>
        ) : (
          <View style={{ flexDirection: "row", gap: theme.space("space-2") }}>
            <Button testID={testID ? `${testID}-resume` : undefined} label="Resume" onPress={onResume} />
            {/* Visually demoted — plain text rather than a secondary button (D-208). */}
            <Button
              testID={testID ? `${testID}-start-over` : undefined}
              label="Start over"
              variant="secondary"
              onPress={() => setConfirmingStartOver(true)}
            />
          </View>
        )}
      </View>
    </Card>
  );
}
