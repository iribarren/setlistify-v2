import React, { useState } from "react";
import { Pressable, Text, type GestureResponderEvent } from "react-native";

import { useTheme } from "@/theme";

import { focusRingStyle } from "./focusRing";

export type ButtonVariant = "primary" | "secondary" | "destructive";

export interface ButtonProps {
  label: string;
  onPress: (event: GestureResponderEvent) => void;
  variant?: ButtonVariant;
  disabled?: boolean;
  /** Overrides the default `accessibilityLabel` (defaults to `label`). */
  accessibilityLabel?: string;
  testID?: string;
}

/**
 * Buttons — `Components.dc.html` ("Buttons"). Three variants × default/hover/pressed/disabled.
 * `hover` has no native equivalent so it is folded into the web-only `Pressable` hover style; the
 * canvas's pressed treatment applies uniformly on every platform.
 */
export function Button({
  label,
  onPress,
  variant = "primary",
  disabled = false,
  accessibilityLabel,
  testID,
}: ButtonProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const [pressed, setPressed] = useState(false);
  const [focused, setFocused] = useState(false);

  const palette = variantPalette(variant, colors);
  const state = disabled ? "disabled" : pressed ? "pressed" : "default";
  const { background, textColor, borderColor } = palette[state];

  return (
    <Pressable
      testID={testID}
      onPress={disabled ? undefined : onPress}
      disabled={disabled}
      onPressIn={() => setPressed(true)}
      onPressOut={() => setPressed(false)}
      onFocus={() => setFocused(true)}
      onBlur={() => setFocused(false)}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
      accessibilityState={{ disabled, busy: false }}
      // AC-4.3: 44x44 minimum hit area on every platform — the control is never shrunk to fit.
      style={[
        {
          minHeight: 44,
          paddingHorizontal: theme.space("space-6"),
          borderRadius: theme.rad("md"),
          alignItems: "center",
          justifyContent: "center",
          backgroundColor: background,
          borderWidth: 1.5,
          borderColor,
        },
        focused && focusRingStyle(theme),
      ]}
    >
      <Text
        style={{
          color: textColor,
          fontFamily: theme.resolveFontFamily("body", "semibold"),
          fontSize: theme.typeScale.base.fontSize,
          lineHeight: theme.typeScale.base.lineHeight,
        }}
      >
        {label}
      </Text>
    </Pressable>
  );
}

interface VariantColors {
  background: string;
  textColor: string;
  borderColor: string;
}

function variantPalette(
  variant: ButtonVariant,
  colors: ReturnType<typeof useTheme>["colors"],
): Record<"default" | "pressed" | "disabled", VariantColors> {
  switch (variant) {
    case "primary":
      return {
        default: {
          background: colors["accent-primary-strong"],
          textColor: colors["surface-raised"],
          borderColor: colors["accent-primary-strong"],
        },
        pressed: {
          background: colors["accent-primary-strong"],
          textColor: colors["surface-raised"],
          borderColor: colors["accent-primary-strong"],
        },
        disabled: {
          background: colors["surface-sunken"],
          textColor: colors["text-tertiary"],
          borderColor: colors["surface-sunken"],
        },
      };
    case "destructive":
      return {
        default: {
          background: colors["error-strong"],
          textColor: colors["surface-raised"],
          borderColor: colors["error-strong"],
        },
        pressed: {
          background: colors["error-strong"],
          textColor: colors["surface-raised"],
          borderColor: colors["error-strong"],
        },
        disabled: {
          background: colors["surface-sunken"],
          textColor: colors["text-tertiary"],
          borderColor: colors["surface-sunken"],
        },
      };
    case "secondary":
    default:
      return {
        default: {
          background: "transparent",
          textColor: colors["text-primary"],
          borderColor: colors["border-strong"],
        },
        pressed: {
          background: colors["surface-sunken"],
          textColor: colors["text-primary"],
          borderColor: colors["text-secondary"],
        },
        disabled: {
          background: "transparent",
          textColor: colors["text-tertiary"],
          borderColor: colors["border-subtle"],
        },
      };
  }
}
