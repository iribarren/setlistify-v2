import React from "react";
import { Text, View } from "react-native";

import type { PlaylistGenerationJobOutput } from "@/lib/playlist";
import { asJobState } from "@/lib/playlist";
import { useTheme } from "@/theme";

export interface GenerationProgressProps {
  job: PlaylistGenerationJobOutput;
  testID?: string;
}

interface StepInfo {
  label: string;
  status: "done" | "active" | "pending";
}

/** `Progress.dc.html` §02: three named steps so the wait reads as work, not a hang (AC-2.1). */
function stepsFor(job: PlaylistGenerationJobOutput): StepInfo[] {
  const state = asJobState(job.state);
  const total = job.songsTotal ?? 0;
  const processed = job.songsProcessed ?? 0;

  const setlistDone = state !== "queued" && state !== "resolving_setlist";
  const matchingDone = state === "building";
  const matchingActive = state === "matching";
  const savingActive = state === "building";

  return [
    { label: setlistDone ? `Found the setlist${total ? ` — ${total} songs` : ""}` : "Finding the setlist", status: setlistDone ? "done" : "active" },
    {
      label: `Matching songs${total ? ` — ${processed} of ${total}` : ""}`,
      status: matchingDone ? "done" : matchingActive ? "active" : "pending",
    },
    { label: "Saving the playlist", status: savingActive ? "active" : "pending" },
  ];
}

/**
 * `Progress.dc.html`. AC-2.1: an indeterminate wait (never a percentage), AC-2.2: the counter is
 * monotonic and driven by the poll — this component only renders what `job` currently says.
 * AC-2.5: never a bare spinner — the steps and "you can leave" reassurance are always present.
 */
export function GenerationProgress({ job, testID }: GenerationProgressProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const steps = stepsFor(job);

  return (
    <View
      testID={testID}
      accessible
      accessibilityRole="progressbar"
      accessibilityLiveRegion="polite"
      accessibilityLabel="Building your playlist"
      style={{ alignItems: "center", padding: theme.space("space-8"), gap: theme.space("space-4") }}
    >
      <View
        style={{
          width: 64,
          height: 64,
          borderRadius: theme.rad("full"),
          borderWidth: 5,
          borderColor: colors["info-bright"],
          borderTopColor: colors["surface-sunken"],
        }}
      />
      <Text
        style={{
          color: theme.colors["text-primary"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize: theme.typeScale.lg.fontSize,
          lineHeight: theme.typeScale.lg.lineHeight,
          textAlign: "center",
        }}
      >
        Building your playlist…
      </Text>
      <View style={{ gap: theme.space("space-2"), width: "100%", maxWidth: 320 }}>
        {steps.map((step) => (
          <View key={step.label} style={{ flexDirection: "row", alignItems: "center", gap: theme.space("space-2") }}>
            <View
              style={{
                width: 18,
                height: 18,
                borderRadius: theme.rad("full"),
                borderWidth: 1.5,
                borderColor: step.status === "active" ? colors["info-strong"] : colors["border-subtle"],
                backgroundColor: step.status === "done" ? colors["success-bright"] + "26" : "transparent",
              }}
            />
            <Text
              style={{
                color: step.status === "pending" ? colors["text-tertiary"] : colors["text-primary"],
                fontFamily: theme.resolveFontFamily("body", step.status === "active" ? "semibold" : "regular"),
                fontSize: theme.typeScale.sm.fontSize,
                lineHeight: theme.typeScale.sm.lineHeight,
              }}
            >
              {step.label}
            </Text>
          </View>
        ))}
      </View>
      <Text
        testID={testID ? `${testID}-patience` : undefined}
        style={{
          color: colors["text-tertiary"],
          fontFamily: theme.resolveFontFamily("body", "regular"),
          fontSize: theme.typeScale.xs.fontSize,
          textAlign: "center",
        }}
      >
        You can leave this screen — we&apos;ll keep going.
      </Text>
    </View>
  );
}
