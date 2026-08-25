import React from "react";
import { Pressable, Text, View } from "react-native";

import { Button } from "@/components";
import { useTheme } from "@/theme";

export interface ModeSheetProps {
  generating: boolean;
  onSelectFast: () => void;
  onSelectChooseYourself: () => void;
  onDismiss: () => void;
  testID?: string;
}

/**
 * `Main.dc.html`'s mode sheet (D-203, closing spec 16's Q-2). Two cards, no forced choice: "Generate
 * playlist" stays the one-tap primary action elsewhere (`GenerateTrigger`) — this is what "Or choose
 * it yourself →" opens. The words "Fast mode"/"Normal mode" never appear in the copy (they're ours,
 * not the user's). Renders inline (this codebase has no separate modal/bottom-sheet primitive —
 * `GenerateTrigger`'s provider chooser uses the same inline-expansion shape on every platform), which
 * satisfies the artboard's intent on both a phone-width and a desktop-width layout without a second
 * component.
 */
export function ModeSheet({
  generating,
  onSelectFast,
  onSelectChooseYourself,
  onDismiss,
  testID,
}: ModeSheetProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;

  return (
    <View testID={testID} style={{ gap: theme.space("space-3") }}>
      <Text
        style={{
          color: colors["text-primary"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize: theme.typeScale.base.fontSize,
        }}
      >
        How do you want to build it?
      </Text>

      <ModeCard
        testID={testID ? `${testID}-fast` : undefined}
        title="Fast"
        body="We pick everything — the latest setlist, best-guess versions."
        disabled={generating}
        onPress={onSelectFast}
      />
      <ModeCard
        testID={testID ? `${testID}-choose-yourself` : undefined}
        title="Choose it yourself"
        body="Pick the show, then confirm anything uncertain."
        disabled={generating}
        onPress={onSelectChooseYourself}
      />

      <Button testID={testID ? `${testID}-dismiss` : undefined} label="Cancel" variant="secondary" onPress={onDismiss} disabled={generating} />
    </View>
  );
}

interface ModeCardProps {
  title: string;
  body: string;
  disabled: boolean;
  onPress: () => void;
  testID?: string;
}

function ModeCard({ title, body, disabled, onPress, testID }: ModeCardProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const [pressed, setPressed] = React.useState(false);

  return (
    <Pressable
      testID={testID}
      onPress={disabled ? undefined : onPress}
      onPressIn={() => setPressed(true)}
      onPressOut={() => setPressed(false)}
      disabled={disabled}
      accessibilityRole="button"
      accessibilityLabel={title}
      style={{
        padding: theme.space("space-4"),
        borderRadius: theme.rad("lg"),
        borderWidth: 1.5,
        borderColor: pressed ? colors["accent-primary-strong"] : colors["border-subtle"],
        backgroundColor: colors["surface-raised"],
        gap: theme.space("space-1"),
        opacity: disabled ? 0.6 : 1,
      }}
    >
      <Text
        style={{
          color: colors["text-primary"],
          fontFamily: theme.resolveFontFamily("body", "semibold"),
          fontSize: theme.typeScale.base.fontSize,
        }}
      >
        {title}
      </Text>
      <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.sm.fontSize, lineHeight: theme.typeScale.sm.lineHeight }}>
        {body}
      </Text>
    </Pressable>
  );
}
