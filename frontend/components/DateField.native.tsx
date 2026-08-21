import React from "react";

import { TextInput } from "@/components";

import type { DateFieldProps } from "./DateFieldTypes";

/**
 * D-34, native half of the one sanctioned platform fork. iOS/Android date-picker dependencies are
 * out of this branch's approved list (D-15's web-support gate hasn't been run against one yet —
 * see spec 07, R-2/"Dependencies" — Open) — so the native field is plain `YYYY-MM-DD` text entry
 * reusing `TextInput`'s exact chrome, same as every other field on the form. Revisit once a
 * cross-platform picker clears the gate; the shared `DateFieldProps` contract does not change.
 */
export function DateField({
  label,
  value,
  onChange,
  errorMessage,
  disabled,
  testID,
}: DateFieldProps): React.JSX.Element {
  return (
    <TextInput
      testID={testID}
      label={label}
      value={value}
      onChangeText={onChange}
      placeholder="YYYY-MM-DD"
      keyboardType="numbers-and-punctuation"
      errorMessage={errorMessage}
      disabled={disabled}
    />
  );
}
