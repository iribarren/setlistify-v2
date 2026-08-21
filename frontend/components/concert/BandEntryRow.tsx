import React from "react";
import { ChevronDown, ChevronUp, X } from "lucide-react-native";
import { Pressable, Text, View } from "react-native";

import { Badge, TextInput } from "@/components";
import { useTheme } from "@/theme";

export interface BandEntryRowProps {
  index: number;
  name: string;
  onChangeName: (name: string) => void;
  onRemove: () => void;
  onMoveUp?: () => void;
  onMoveDown?: () => void;
  canRemove: boolean;
  errorMessage?: string;
  testID?: string;
}

/**
 * Band entry row — `NewComponents.dc.html`. AC-3.2: row order IS billing order — index 0 shows a
 * "Headliner" badge, every later row shows its ordinal — and reordering is one tap (up/down) rather
 * than drag-and-drop, which doesn't have an accessible equivalent on every platform.
 */
export function BandEntryRow({
  index,
  name,
  onChangeName,
  onRemove,
  onMoveUp,
  onMoveDown,
  canRemove,
  errorMessage,
  testID,
}: BandEntryRowProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;

  return (
    <View style={{ flexDirection: "row", alignItems: "flex-start", gap: theme.space("space-2") }}>
      <View style={{ flex: 1 }}>
        <TextInput
          testID={testID}
          label={index === 0 ? "Headliner" : `Band ${index + 1}`}
          value={name}
          onChangeText={onChangeName}
          placeholder="Band name"
          errorMessage={errorMessage}
        />
      </View>
      <View style={{ flexDirection: "row", alignItems: "center", gap: theme.space("space-1"), marginTop: 30 }}>
        {index === 0 ? (
          <Badge label="Headliner" variant="info" />
        ) : (
          <>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={`Move ${name || "this band"} up`}
              disabled={!onMoveUp}
              onPress={onMoveUp}
              style={{ minWidth: 32, minHeight: 32, alignItems: "center", justifyContent: "center", opacity: onMoveUp ? 1 : 0.3 }}
            >
              <ChevronUp size={18} color={colors["text-secondary"]} />
            </Pressable>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={`Move ${name || "this band"} down`}
              disabled={!onMoveDown}
              onPress={onMoveDown}
              style={{ minWidth: 32, minHeight: 32, alignItems: "center", justifyContent: "center", opacity: onMoveDown ? 1 : 0.3 }}
            >
              <ChevronDown size={18} color={colors["text-secondary"]} />
            </Pressable>
          </>
        )}
        {canRemove ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={`Remove ${name || "this band"}`}
            onPress={onRemove}
            style={{ minWidth: 32, minHeight: 32, alignItems: "center", justifyContent: "center" }}
          >
            <X size={18} color={colors["error-strong"]} />
          </Pressable>
        ) : null}
      </View>
    </View>
  );
}

// Re-exported so screens can label the section without hardcoding copy per-call-site.
export const LINEUP_CAPTION = "Lineup — billing order, headliner first";
export function LineupCaption(): React.JSX.Element {
  const theme = useTheme();
  return (
    <Text
      style={{
        color: theme.colors["text-tertiary"],
        fontFamily: theme.resolveFontFamily("body", "regular"),
        fontSize: theme.typeScale.xs.fontSize,
        lineHeight: theme.typeScale.xs.lineHeight,
      }}
    >
      {LINEUP_CAPTION}
    </Text>
  );
}
