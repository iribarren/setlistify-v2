import React from "react";
import { Text, View } from "react-native";

import { Button, Card } from "@/components";
import { useTheme } from "@/theme";

export interface DisconnectConfirmationProps {
  providerLabel: string;
  onConfirm: () => void;
  onCancel: () => void;
  disconnecting: boolean;
  testID?: string;
}

/**
 * AC-3.6: a destructive confirmation before the `DELETE`, naming the provider — mirrors
 * `components/concert/DeleteConfirmation.tsx`'s shape (D-40's pattern) for the streaming account.
 */
export function DisconnectConfirmation({
  providerLabel,
  onConfirm,
  onCancel,
  disconnecting,
  testID,
}: DisconnectConfirmationProps): React.JSX.Element {
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
          Disconnect {providerLabel}?
        </Text>
        <Text
          style={{
            color: theme.colors["text-secondary"],
            fontFamily: theme.resolveFontFamily("body", "regular"),
            fontSize: theme.typeScale.sm.fontSize,
            lineHeight: theme.typeScale.sm.lineHeight,
          }}
        >
          Setlistify will delete its copy of your {providerLabel} connection and can no longer act
          on your behalf — this can&apos;t be undone from here.
        </Text>
        <View
          style={{ flexDirection: "row", gap: theme.space("space-3"), justifyContent: "flex-end" }}
        >
          <Button
            testID="disconnect-cancel"
            label="Cancel"
            variant="secondary"
            onPress={onCancel}
            disabled={disconnecting}
          />
          <Button
            testID="disconnect-confirm"
            label={disconnecting ? "Disconnecting…" : "Disconnect"}
            variant="destructive"
            onPress={onConfirm}
            disabled={disconnecting}
          />
        </View>
      </View>
    </Card>
  );
}
