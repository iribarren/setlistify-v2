import React from "react";
import { View } from "react-native";

import { Avatar, Badge, Button, ListRow, type BadgeVariant } from "@/components";
import {
  providerDisplayName,
  type StreamingAccountOutput,
  type StreamingAccountStatus,
} from "@/lib/streaming";
import { useTheme } from "@/theme";

export interface StreamingAccountRowProps {
  account: StreamingAccountOutput;
  /** True while THIS row's reconnect round trip is in flight (AC-5.3). */
  reconnecting: boolean;
  onReconnect: () => void;
  onDisconnect: () => void;
  testID?: string;
}

const STATUS_LABEL: Record<StreamingAccountStatus, string> = {
  connected: "Connected",
  needs_reauth: "Needs reconnect",
  revoked_by_user: "Revoked",
};

const STATUS_VARIANT: Record<StreamingAccountStatus, BadgeVariant> = {
  connected: "success",
  needs_reauth: "warning",
  revoked_by_user: "error",
};

function asStatus(value: string | undefined): StreamingAccountStatus {
  if (value === "connected" || value === "needs_reauth" || value === "revoked_by_user") {
    return value;
  }
  // AC-2.2: an unrecognised status renders as the most cautious state rather than assumed healthy.
  return "needs_reauth";
}

function formatLinkedAt(linkedAt: string | undefined): string | null {
  if (!linkedAt) {
    return null;
  }
  const date = new Date(linkedAt);
  if (Number.isNaN(date.getTime())) {
    return null;
  }
  return `Linked ${date.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" })}`;
}

/**
 * AC-2.5: renders the three statuses distinctly (label + color, never color alone) and keeps every
 * action a 44×44 `Button` — reused as-is, not a bespoke pressable.
 */
export function StreamingAccountRow({
  account,
  reconnecting,
  onReconnect,
  onDisconnect,
  testID,
}: StreamingAccountRowProps): React.JSX.Element {
  const theme = useTheme();
  const status = asStatus(account.status);
  const name = providerDisplayName(account.provider ?? "");
  const linkedAt = formatLinkedAt(account.linkedAt);
  const subtitle = [linkedAt, account.providerAccountId ? `@${account.providerAccountId}` : null]
    .filter(Boolean)
    .join(" · ");

  return (
    // `testID` on its own View: `ListRow` only forwards `testID` to the `Pressable` it renders when
    // given an `onPress`, and this row deliberately has none — its nested action buttons would
    // otherwise sit inside one giant pressable row (a nested-touchable trap, not just a test-id gap).
    <View testID={testID}>
      <ListRow
        leading={<Avatar initials={name.slice(0, 2).toUpperCase() || "?"} size={40} />}
        title={name}
        subtitle={subtitle || undefined}
        accessibilityLabel={`${name}, ${STATUS_LABEL[status]}`}
        trailing={
          <View style={{ alignItems: "flex-end", gap: theme.space("space-2") }}>
            <Badge label={STATUS_LABEL[status]} variant={STATUS_VARIANT[status]} />
            <View style={{ flexDirection: "row", gap: theme.space("space-2") }}>
              {status !== "connected" ? (
                <Button
                  testID={testID ? `${testID}-reconnect` : undefined}
                  label={reconnecting ? "Reconnecting…" : "Reconnect"}
                  variant="secondary"
                  onPress={onReconnect}
                  disabled={reconnecting}
                />
              ) : null}
              <Button
                testID={testID ? `${testID}-disconnect` : undefined}
                label="Disconnect"
                variant="destructive"
                onPress={onDisconnect}
              />
            </View>
          </View>
        }
      />
    </View>
  );
}
