/**
 * D-34: the single shared prop contract for the app's one sanctioned platform fork
 * (`DateField.native.tsx` / `DateField.web.tsx`). No screen imports `Platform` directly — a screen
 * imports `@/components/DateField` and Metro/Jest/`tsc` (via `tsconfig.json`'s `moduleSuffixes`,
 * same mechanism as `lib/auth/storage.*`) resolve the right file for the platform it's building.
 */
export interface DateFieldProps {
  label: string;
  /** ISO-8601 calendar date, `YYYY-MM-DD`, or `""` while unset. */
  value: string;
  onChange: (value: string) => void;
  /** `YYYY-MM-DD` (D-31's lower bound). */
  minDate?: string;
  /** `YYYY-MM-DD` (D-31's upper bound). */
  maxDate?: string;
  errorMessage?: string;
  disabled?: boolean;
  testID?: string;
}
