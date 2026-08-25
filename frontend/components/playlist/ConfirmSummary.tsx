import React from "react";
import { Text, View } from "react-native";

import { Button } from "@/components";
import type { PendingChoiceDecisionOutput, PendingChoicesOutput } from "@/lib/playlist";
import { useTheme } from "@/theme";

export interface ConfirmSummaryProps {
  pendingChoices: PendingChoicesOutput;
  /** sourcePosition -> chosen providerTrackId, `null` for a decline (AC-2.6). */
  choices: Record<number, string | null>;
  onBack: () => void;
  onBuild: () => void;
  building: boolean;
  testID?: string;
}

/**
 * `Confirm.dc.html` — D-194: a client-side-only step. It reads "step 3 of 3" to the user, but there
 * is no third server state: "Build the playlist" literally calls `POST …/version-choices`
 * (`onBuild`). AC-6.1: `onBack` is pure client-side navigation back into the version list — no
 * request, and the draft is untouched either way.
 */
export function ConfirmSummary({ pendingChoices, choices, onBack, onBuild, building, testID }: ConfirmSummaryProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const decisions = (pendingChoices.decisions ?? []).filter(
    (decision): decision is PendingChoiceDecisionOutput & { sourcePosition: number } => decision.sourcePosition != null,
  );
  const autoResolvedCount = pendingChoices.autoResolvedCount ?? 0;
  // D-194: traceable to the previous screen's own counts, not re-derived from anything else.
  const confirmedCount = decisions.length;
  const total = autoResolvedCount + confirmedCount;

  function candidateTitleFor(sourcePosition: number, providerTrackId: string | null): string {
    if (providerTrackId === null) {
      return "Skipped — none of these";
    }
    const decision = decisions.find((entry) => entry.sourcePosition === sourcePosition);
    const candidate = (decision?.candidates ?? []).find((entry) => entry.providerTrackId === providerTrackId);
    return candidate?.title ?? "Selected";
  }

  return (
    <View testID={testID} style={{ gap: theme.space("space-5") }}>
      <View style={{ gap: theme.space("space-1") }}>
        <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>Step 3 of 3</Text>
        <Text
          style={{ color: colors["text-primary"], fontFamily: theme.resolveFontFamily("display", "semibold"), fontSize: theme.typeScale.lg.fontSize }}
        >
          Ready to build
        </Text>
        <Text
          testID={testID ? `${testID}-count` : undefined}
          style={{ color: colors["text-secondary"], fontSize: theme.typeScale.sm.fontSize }}
        >
          {autoResolvedCount} automatic + {confirmedCount} confirmed = {total} songs
        </Text>
      </View>

      {decisions.length > 0 ? (
        <View style={{ gap: theme.space("space-1") }}>
          {decisions.map((decision) => (
            <View
              key={decision.sourcePosition}
              style={{ flexDirection: "row", justifyContent: "space-between", paddingVertical: theme.space("space-1") }}
            >
              <Text style={{ color: colors["text-primary"], fontSize: theme.typeScale.sm.fontSize, flex: 1 }}>{decision.sourceTitle}</Text>
              <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize, flex: 1, textAlign: "right" }}>
                {candidateTitleFor(
                  decision.sourcePosition,
                  decision.sourcePosition in choices ? choices[decision.sourcePosition] : ((decision.candidates ?? [])[0]?.providerTrackId ?? null),
                )}
              </Text>
            </View>
          ))}
        </View>
      ) : null}

      <View style={{ flexDirection: "row", gap: theme.space("space-3") }}>
        <Button testID={testID ? `${testID}-back` : undefined} label="Back" variant="secondary" onPress={onBack} disabled={building} />
        <Button testID={testID ? `${testID}-build` : undefined} label={building ? "Building…" : "Build the playlist"} onPress={onBuild} disabled={building} />
      </View>
    </View>
  );
}
