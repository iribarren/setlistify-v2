import React from "react";
import { Text, View } from "react-native";

import { Button, Card } from "@/components";
import { useTheme } from "@/theme";

export interface DeletePlaylistConfirmationProps {
  providerDisplayName: string;
  onConfirm: () => void;
  onCancel: () => void;
  deleting: boolean;
  testID?: string;
}

/**
 * D-151/D-173: mirrors `DeleteConfirmation.tsx`'s shape but states plainly that the provider-side
 * playlist survives — "removed from Setlistify; the playlist stays in your <Provider> account until
 * you delete it there." Copy that implies otherwise is a bug (T-14).
 */
export function DeletePlaylistConfirmation({
  providerDisplayName,
  onConfirm,
  onCancel,
  deleting,
  testID,
}: DeletePlaylistConfirmationProps): React.JSX.Element {
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
          Delete this playlist?
        </Text>
        <Text
          style={{
            color: theme.colors["text-secondary"],
            fontFamily: theme.resolveFontFamily("body", "regular"),
            fontSize: theme.typeScale.sm.fontSize,
            lineHeight: theme.typeScale.sm.lineHeight,
          }}
        >
          It&apos;ll be removed from Setlistify; the playlist stays in your {providerDisplayName} account
          until you delete it there.
        </Text>
        <View style={{ flexDirection: "row", gap: theme.space("space-3"), justifyContent: "flex-end" }}>
          <Button testID="delete-playlist-cancel" label="Cancel" variant="secondary" onPress={onCancel} disabled={deleting} />
          <Button
            testID="delete-playlist-confirm"
            label={deleting ? "Deleting…" : "Delete playlist"}
            variant="destructive"
            onPress={onConfirm}
            disabled={deleting}
          />
        </View>
      </View>
    </Card>
  );
}
