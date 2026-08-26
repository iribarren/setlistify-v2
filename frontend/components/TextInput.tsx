import React, { useId, useState } from "react";
import {
  Text,
  TextInput as RNTextInput,
  View,
  type TextInputProps as RNTextInputProps,
  type TextStyle,
} from "react-native";

import { useTheme } from "@/theme";

import { focusRingStyle } from "./focusRing";

export interface TextInputProps
  extends Pick<
    RNTextInputProps,
    | "value"
    | "onChangeText"
    | "placeholder"
    | "keyboardType"
    | "secureTextEntry"
    | "autoFocus"
    | "multiline"
    | "numberOfLines"
  > {
  label: string;
  errorMessage?: string;
  disabled?: boolean;
  testID?: string;
}

/**
 * Text input — `Components.dc.html` ("Text & date inputs"): default/focus/error/disabled, with a
 * label above and an error message below. A date input reuses this exact chrome per the canvas
 * note — this branch does not build a separate date picker (D-16, out of scope).
 */
export function TextInput({
  label,
  errorMessage,
  disabled = false,
  value,
  onChangeText,
  placeholder,
  keyboardType,
  secureTextEntry,
  autoFocus,
  multiline,
  numberOfLines,
  testID,
}: TextInputProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const [focused, setFocused] = useState(false);
  const inputId = useId();
  const hasError = Boolean(errorMessage);

  const borderColor = disabled
    ? colors["border-subtle"]
    : hasError
      ? colors["error-strong"]
      : colors["border-strong"];

  return (
    <View>
      <Text
        nativeID={`${inputId}-label`}
        style={{
          color: colors["text-primary"],
          fontFamily: theme.resolveFontFamily("body", "semibold"),
          fontSize: theme.typeScale.sm.fontSize,
          lineHeight: theme.typeScale.sm.lineHeight,
          marginBottom: theme.space("space-2"),
        }}
      >
        {label}
      </Text>
      <RNTextInput
        testID={testID}
        value={value}
        onChangeText={onChangeText}
        placeholder={placeholder}
        placeholderTextColor={colors["text-tertiary"]}
        keyboardType={keyboardType}
        secureTextEntry={secureTextEntry}
        autoFocus={autoFocus}
        multiline={multiline}
        numberOfLines={numberOfLines}
        editable={!disabled}
        onFocus={() => setFocused(true)}
        onBlur={() => setFocused(false)}
        accessibilityLabelledBy={`${inputId}-label`}
        accessibilityState={{ disabled }}
        aria-invalid={hasError}
        style={[
          {
            minHeight: multiline ? 46 * Math.max(numberOfLines ?? 3, 1) : 46,
            borderRadius: theme.rad("sm"),
            paddingHorizontal: theme.space("space-4"),
            paddingVertical: multiline ? theme.space("space-3") : undefined,
            textAlignVertical: multiline ? "top" : undefined,
            borderWidth: 1.5,
            borderColor,
            backgroundColor: disabled ? colors["surface-sunken"] : colors["surface-raised"],
            color: disabled ? colors["text-tertiary"] : colors["text-primary"],
            fontFamily: theme.resolveFontFamily("body", "regular"),
            fontSize: theme.typeScale.base.fontSize,
          },
          // focusRingStyle() is typed as ViewStyle (it's shared with View-based components like
          // Button); its actual properties (border/outline) are equally valid on RN's TextInput,
          // which types its style as TextStyle — a narrower interface for an unrelated reason
          // (text-specific props like `userSelect`'s literal union), not an incompatible one.
          focused && !disabled && (focusRingStyle(theme) as TextStyle),
        ]}
      />
      {hasError ? (
        <Text
          accessibilityRole="alert"
          style={{
            color: colors["error-strong"],
            fontFamily: theme.resolveFontFamily("body", "regular"),
            fontSize: theme.typeScale.sm.fontSize,
            marginTop: theme.space("space-2"),
          }}
        >
          {errorMessage}
        </Text>
      ) : null}
    </View>
  );
}
