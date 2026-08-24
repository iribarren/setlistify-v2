import React, { useState } from "react";
import { Text, View } from "react-native";

import { Button } from "@/components";
import type { ProviderCandidate } from "@/lib/playlist";
import { useTheme } from "@/theme";

export interface GenerateTriggerProps {
  /** AC-1.3: 0 candidates — link-an-account prompt instead of a chooser (D-169). */
  hasCandidates: boolean;
  /** >1 candidates with no default — a bottom-sheet-style inline choice (D-169). */
  choiceCandidates: ProviderCandidate[] | null;
  generating: boolean;
  onGenerate: (provider?: string) => void;
  onLinkAccount: () => void;
  testID?: string;
}

/**
 * `Main.dc.html` ("01 · Concert page CTA"). AC-1.1: the primary trigger when the concert has no
 * playlist and no live job. The "choose it yourself" link/sheet from the canvas is NOT built here —
 * Fast mode ships as the one-tap default only (D-171, Q-2).
 */
export function GenerateTrigger({
  hasCandidates,
  choiceCandidates,
  generating,
  onGenerate,
  onLinkAccount,
  testID,
}: GenerateTriggerProps): React.JSX.Element {
  const theme = useTheme();
  const [choosing, setChoosing] = useState(false);

  if (!hasCandidates) {
    return (
      <View testID={testID ? `${testID}-link-prompt` : undefined} style={{ gap: theme.space("space-3") }}>
        <Text
          style={{
            color: theme.colors["text-secondary"],
            fontFamily: theme.resolveFontFamily("body", "regular"),
            fontSize: theme.typeScale.sm.fontSize,
            lineHeight: theme.typeScale.sm.lineHeight,
          }}
        >
          Connect a streaming account to generate a playlist for this concert.
        </Text>
        <Button testID={testID ? `${testID}-connect` : undefined} label="Connect an account" onPress={onLinkAccount} />
      </View>
    );
  }

  if (choiceCandidates && choiceCandidates.length > 1 && !choosing) {
    return (
      <Button
        testID={testID}
        label="Generate playlist"
        onPress={() => setChoosing(true)}
        disabled={generating}
      />
    );
  }

  if (choiceCandidates && choiceCandidates.length > 1 && choosing) {
    return (
      <View testID={testID ? `${testID}-choice` : undefined} style={{ gap: theme.space("space-2") }}>
        <Text
          style={{
            color: theme.colors["text-primary"],
            fontFamily: theme.resolveFontFamily("display", "semibold"),
            fontSize: theme.typeScale.base.fontSize,
          }}
        >
          Which streaming account?
        </Text>
        {choiceCandidates.map((candidate) => (
          <Button
            key={candidate.key}
            testID={`${testID}-choice-${candidate.key}`}
            label={candidate.displayName}
            variant="secondary"
            disabled={generating}
            onPress={() => onGenerate(candidate.key)}
          />
        ))}
      </View>
    );
  }

  // AC-1.3: exactly one candidate, or many with a default — used silently, no chooser.
  return (
    <Button
      testID={testID}
      label={generating ? "Starting…" : "Generate playlist"}
      disabled={generating}
      onPress={() => onGenerate()}
    />
  );
}
