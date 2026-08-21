import React, { createElement, useId } from "react";
import { Text, View } from "react-native";

import { useTheme } from "@/theme";

import type { DateFieldProps } from "./DateFieldTypes";

/**
 * D-34, web half of the one sanctioned platform fork. The browser's native `<input type="date">`
 * gives a real calendar picker for free, with no extra dependency to clear D-15's web-support gate
 * — react-native-web already renders on top of the DOM, so a plain intrinsic element is legitimate
 * here (via `createElement` rather than JSX, since the RN JSX namespace doesn't declare `"input"`).
 */
export function DateField({
  label,
  value,
  onChange,
  minDate,
  maxDate,
  errorMessage,
  disabled,
  testID,
}: DateFieldProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
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
      {createElement("input", {
        id: inputId,
        "data-testid": testID,
        type: "date",
        value,
        min: minDate,
        max: maxDate,
        disabled,
        "aria-invalid": hasError,
        "aria-labelledby": `${inputId}-label`,
        onChange: (event: { target: { value: string } }) => onChange(event.target.value),
        style: {
          minHeight: 46,
          width: "100%",
          boxSizing: "border-box",
          borderRadius: theme.rad("sm"),
          paddingLeft: theme.space("space-4"),
          paddingRight: theme.space("space-4"),
          borderWidth: 1.5,
          borderStyle: "solid",
          borderColor,
          backgroundColor: disabled ? colors["surface-sunken"] : colors["surface-raised"],
          color: disabled ? colors["text-tertiary"] : colors["text-primary"],
          fontFamily: theme.resolveFontFamily("body", "regular"),
          fontSize: theme.typeScale.base.fontSize,
        },
      })}
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
