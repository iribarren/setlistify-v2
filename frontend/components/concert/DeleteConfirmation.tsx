import React from "react";
import { Text, View } from "react-native";

import { Button, Card } from "@/components";
import { useTheme } from "@/theme";

export interface DeleteConfirmationProps {
  concertLabel: string;
  onConfirm: () => void;
  onCancel: () => void;
  deleting: boolean;
  testID?: string;
}

/**
 * `EditDelete.dc.html` ("Delete this concert?"). D-40: the API hard-deletes (spec 05, AC-6.5) — the
 * copy says so plainly rather than implying an undo that doesn't exist. AC-7.1: names the concert
 * so a destructive confirmation is never generic.
 */
export function DeleteConfirmation({
  concertLabel,
  onConfirm,
  onCancel,
  deleting,
  testID,
}: DeleteConfirmationProps): React.JSX.Element {
  const theme = useTheme();

  return (
    <Card testID={testID}>
      <View style={{ gap: theme.space("space-4") }}>
        <Text
          style={{
            color: theme.colors["text-primary"],
            fontFamily: theme.resolveFontFamily("display", "semibold"),
            fontSize: theme.typeScale.lg.fontSize,
            lineHeight: theme.typeScale.lg.lineHeight,
          }}
        >
          Delete this concert?
        </Text>
        <Text
          style={{
            color: theme.colors["text-secondary"],
            fontFamily: theme.resolveFontFamily("body", "regular"),
            fontSize: theme.typeScale.sm.fontSize,
            lineHeight: theme.typeScale.sm.lineHeight,
          }}
        >
          {concertLabel} will be permanently deleted — this can&apos;t be undone.
        </Text>
        <View style={{ flexDirection: "row", gap: theme.space("space-3"), justifyContent: "flex-end" }}>
          <Button testID="delete-cancel" label="Cancel" variant="secondary" onPress={onCancel} disabled={deleting} />
          <Button
            testID="delete-confirm"
            label={deleting ? "Deleting…" : "Delete concert"}
            variant="destructive"
            onPress={onConfirm}
            disabled={deleting}
          />
        </View>
      </View>
    </Card>
  );
}
