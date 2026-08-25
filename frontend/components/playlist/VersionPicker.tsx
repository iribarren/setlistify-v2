import React, { useState } from "react";
import { Pressable, Text, View } from "react-native";

import { Badge, Button } from "@/components";
import { describeConfidence, describeReportCode } from "@/lib/playlist";
import type {
  PendingChoiceAutoResolvedOutput,
  PendingChoiceCandidateOutput,
  PendingChoiceDecisionOutput,
  PendingChoicesOutput,
} from "@/lib/playlist";
import { useTheme } from "@/theme";

export interface VersionPickerProps {
  pendingChoices: PendingChoicesOutput;
  /** Draft overrides (`choices.ts`). A key present with `null` is an explicit "none of these" (AC-2.6). */
  choices: Record<number, string | null>;
  onChoose: (sourcePosition: number, providerTrackId: string | null) => void;
  /** AC-6.1: purely client-side — moves to the confirm sub-step, no request. */
  onContinue: () => void;
  testID?: string;
}

/** AC-2.3: candidates are pre-ordered by the backend, but pick defensively — top_pick/only_match first. */
export function defaultCandidateFor(decision: PendingChoiceDecisionOutput): PendingChoiceCandidateOutput | null {
  const candidates = decision.candidates ?? [];
  const byPriority = ["top_pick", "only_match", "your_previous_choice", "alternative"];
  for (const label of byPriority) {
    const match = candidates.find((candidate) => candidate.label === label);
    if (match) {
      return match;
    }
  }
  return candidates[0] ?? null;
}

function formatDuration(durationMs: number | null | undefined): string | null {
  if (durationMs == null || durationMs <= 0) {
    return null;
  }
  const totalSeconds = Math.round(durationMs / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes}:${String(seconds).padStart(2, "0")}`;
}

/**
 * `VersionSelect.dc.html` (US-2). AC-2.2: auto-resolved songs collapse into one expandable summary,
 * never demanding a tap. AC-2.3: every decision pre-selects the top candidate — submitting with zero
 * taps is a legitimate, complete path. AC-2.5: confidence is rendered ONLY through `confidence.ts`'s
 * closed vocabulary — no raw number, percentage or star ever appears in this tree.
 */
export function VersionPicker({ pendingChoices, choices, onChoose, onContinue, testID }: VersionPickerProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const [autoResolvedExpanded, setAutoResolvedExpanded] = useState(false);
  const autoResolved = pendingChoices.autoResolved ?? [];
  const decisions = pendingChoices.decisions ?? [];

  return (
    <View testID={testID} style={{ gap: theme.space("space-5") }}>
      <Text
        style={{
          color: colors["text-primary"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize: theme.typeScale.lg.fontSize,
        }}
      >
        Confirm the uncertain ones
      </Text>

      {autoResolved.length > 0 ? (
        <View testID={testID ? `${testID}-auto-resolved` : undefined} style={{ gap: theme.space("space-2") }}>
          <Pressable
            testID={testID ? `${testID}-auto-resolved-toggle` : undefined}
            onPress={() => setAutoResolvedExpanded((expanded) => !expanded)}
            accessibilityRole="button"
            style={{
              flexDirection: "row",
              justifyContent: "space-between",
              alignItems: "center",
              padding: theme.space("space-3"),
              borderRadius: theme.rad("md"),
              backgroundColor: `${colors["success-bright"]}26`,
            }}
          >
            <Text style={{ color: colors["success-strong"], fontFamily: theme.resolveFontFamily("body", "semibold"), fontSize: theme.typeScale.sm.fontSize }}>
              {autoResolved.length} song{autoResolved.length === 1 ? "" : "s"} matched automatically
            </Text>
            <Text style={{ color: colors["success-strong"], fontSize: theme.typeScale.xs.fontSize }}>
              {autoResolvedExpanded ? "Hide" : "Review"}
            </Text>
          </Pressable>
          {autoResolvedExpanded ? (
            <View style={{ gap: theme.space("space-2") }}>
              {autoResolved.map((entry) => (
                <AutoResolvedRow key={entry.sourcePosition} entry={entry} />
              ))}
            </View>
          ) : null}
        </View>
      ) : null}

      <View style={{ gap: theme.space("space-4") }}>
        {decisions
          .filter((decision): decision is PendingChoiceDecisionOutput & { sourcePosition: number } => decision.sourcePosition != null)
          .map((decision) => (
            <DecisionCard
              key={`${decision.sourcePosition}-${decision.segmentIndex ?? 0}`}
              decision={decision}
              selected={
                decision.sourcePosition in choices ? choices[decision.sourcePosition] : (defaultCandidateFor(decision)?.providerTrackId ?? null)
              }
              onSelect={(providerTrackId) => onChoose(decision.sourcePosition, providerTrackId)}
              testID={testID ? `${testID}-decision-${decision.sourcePosition}` : undefined}
            />
          ))}
      </View>

      <Button testID={testID ? `${testID}-continue` : undefined} label="Continue" onPress={onContinue} />
    </View>
  );
}

function AutoResolvedRow({ entry }: { entry: PendingChoiceAutoResolvedOutput }): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const chip = describeConfidence(entry.label);

  return (
    <View style={{ flexDirection: "row", alignItems: "center", gap: theme.space("space-2"), paddingVertical: theme.space("space-1") }}>
      <View style={{ flex: 1 }}>
        <Text style={{ color: colors["text-primary"], fontSize: theme.typeScale.sm.fontSize }}>{entry.sourceTitle}</Text>
        <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>{entry.bandName}</Text>
        {entry.reasonCode ? (
          <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize, marginTop: 2 }}>
            {describeReportCode(entry.reasonCode, entry.reasonParams)}
          </Text>
        ) : null}
      </View>
      <Badge label={chip.label} variant={chip.variant} />
    </View>
  );
}

interface DecisionCardProps {
  decision: PendingChoiceDecisionOutput;
  selected: string | null;
  onSelect: (providerTrackId: string | null) => void;
  testID?: string;
}

function DecisionCard({ decision, selected, onSelect, testID }: DecisionCardProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;

  return (
    <View testID={testID} style={{ gap: theme.space("space-2") }}>
      <Text style={{ color: colors["text-primary"], fontFamily: theme.resolveFontFamily("body", "semibold"), fontSize: theme.typeScale.sm.fontSize }}>
        {decision.sourceTitle}
      </Text>
      <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>
        {decision.bandName}
        {decision.reasonCode ? ` · ${describeReportCode(decision.reasonCode, decision.reasonParams)}` : ""}
      </Text>
      <View style={{ gap: theme.space("space-2") }}>
        {(decision.candidates ?? [])
          .filter((candidate): candidate is PendingChoiceCandidateOutput & { providerTrackId: string } => candidate.providerTrackId != null)
          .map((candidate) => (
            <CandidateOption
              key={candidate.providerTrackId}
              candidate={candidate}
              selected={selected === candidate.providerTrackId}
              onPress={() => onSelect(candidate.providerTrackId)}
              testID={testID ? `${testID}-candidate-${candidate.providerTrackId}` : undefined}
            />
          ))}
        <Pressable
          testID={testID ? `${testID}-none` : undefined}
          onPress={() => onSelect(null)}
          accessibilityRole="radio"
          accessibilityState={{ checked: selected === null }}
          style={{
            padding: theme.space("space-3"),
            borderRadius: theme.rad("md"),
            borderWidth: 1.5,
            borderColor: selected === null ? colors["accent-primary-strong"] : colors["border-subtle"],
          }}
        >
          <Text style={{ color: colors["text-secondary"], fontSize: theme.typeScale.sm.fontSize }}>None of these</Text>
        </Pressable>
      </View>
    </View>
  );
}

function CandidateOption({
  candidate,
  selected,
  onPress,
  testID,
}: {
  candidate: PendingChoiceCandidateOutput;
  selected: boolean;
  onPress: () => void;
  testID?: string;
}): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const chip = describeConfidence(candidate.label);
  const duration = formatDuration(candidate.durationMs);
  const detailParts = [candidate.artistName, candidate.albumName, candidate.releaseYear ? String(candidate.releaseYear) : null, duration].filter(
    Boolean,
  );

  return (
    <Pressable
      testID={testID}
      onPress={onPress}
      accessibilityRole="radio"
      accessibilityState={{ checked: selected }}
      style={{
        flexDirection: "row",
        alignItems: "center",
        gap: theme.space("space-3"),
        padding: theme.space("space-3"),
        borderRadius: theme.rad("md"),
        borderWidth: 1.5,
        borderColor: selected ? colors["accent-primary-strong"] : colors["border-subtle"],
        backgroundColor: selected ? `${colors["info-bright"]}1a` : colors["surface-raised"],
      }}
    >
      <View style={{ flex: 1 }}>
        <Text style={{ color: colors["text-primary"], fontFamily: theme.resolveFontFamily("body", "semibold"), fontSize: theme.typeScale.sm.fontSize }}>
          {candidate.title ?? "Untitled"}
        </Text>
        {detailParts.length > 0 ? (
          <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>{detailParts.join(" · ")}</Text>
        ) : null}
        <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize, marginTop: 2 }}>{chip.reason}</Text>
      </View>
      <Badge label={chip.label} variant={chip.variant} />
    </Pressable>
  );
}
