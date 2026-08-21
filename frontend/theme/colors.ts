/**
 * Color tokens transcribed verbatim from `docs/design/canvas/Main.dc.html` ("01 · Palette — light
 * & dark") and cross-checked against `Accessibility.dc.html` ("Verified text contrast").
 *
 * AC-2.1/AC-2.2: every hex value below traces to a named swatch on the canvas — do not hand-tune a
 * value here; change the canvas first (AC-2.7).
 *
 * The canvas gives light mode two accent/semantic variants per hue (`-strong` for AA text,
 * `-bright` for fills/icons only) but collapses each pair to a single AA-verified value in dark
 * mode (there is only one `accent-primary`, one `success`, etc.). To keep every consumer
 * theme-agnostic (AC-2.3 — components never branch on light vs. dark), both the light and the dark
 * palette are typed with the same key set: in dark mode the `-strong` and `-bright` keys for a
 * given hue intentionally resolve to the SAME single value the canvas defines for that hue, because
 * that one value already clears AA on its own (Accessibility.dc.html, dark rows).
 */

export type ColorToken =
  | "bg"
  | "surface-raised"
  | "surface-sunken"
  | "border-subtle"
  | "border-strong"
  | "text-primary"
  | "text-secondary"
  | "text-tertiary"
  | "accent-primary-strong"
  | "accent-primary-bright"
  | "accent-secondary-strong"
  | "accent-secondary-bright"
  | "success-strong"
  | "success-bright"
  | "warning-strong"
  | "warning-bright"
  | "error-strong"
  | "error-bright"
  | "info-strong"
  | "info-bright";

export type ColorTokens = Record<ColorToken, string>;

// Main.dc.html, ".panel.light" — "Daylight, browsing at home — warm paper, not clinical white."
export const lightColors: ColorTokens = {
  "bg": "#faf8f4",
  "surface-raised": "#fffffc",
  "surface-sunken": "#f1eee9",
  "border-subtle": "#e1ded7",
  "border-strong": "#4a4742",

  "text-primary": "#221812",
  "text-secondary": "#312620",
  "text-tertiary": "#4f4641",

  "accent-primary-strong": "#871600",
  "accent-primary-bright": "#d16022",

  "accent-secondary-strong": "#501a65",
  "accent-secondary-bright": "#9760af",

  "success-strong": "#003d00",
  "success-bright": "#308639",
  "warning-strong": "#522700",
  "warning-bright": "#a97600",
  "error-strong": "#980035",
  "error-bright": "#db4b6d",
  "info-strong": "#00336a",
  "info-bright": "#2382ba",
};

// Main.dc.html, ".panel.dark" — "The venue. Near-black with a warm ember undertone, never pure
// #000." AC-3.3: `bg` is deliberately #0f0a08, not #000000.
export const darkColors: ColorTokens = {
  "bg": "#0f0a08",
  "surface-raised": "#1b1511",
  "surface-sunken": "#080503",
  "border-subtle": "#2e2724",
  "border-strong": "#5b534f",

  "text-primary": "#f1eee9",
  "text-secondary": "#b1aea7",
  "text-tertiary": "#7d7a74",

  // Dark mode has one accent-primary value (#f59145, 7.4:1 AA) — see file header.
  "accent-primary-strong": "#f59145",
  "accent-primary-bright": "#f59145",

  // Dark mode has one accent-secondary value (#c38adc, 7.2:1 AA).
  "accent-secondary-strong": "#c38adc",
  "accent-secondary-bright": "#c38adc",

  "success-strong": "#5bb661",
  "success-bright": "#5bb661",
  "warning-strong": "#e0af3b",
  "warning-bright": "#e0af3b",
  "error-strong": "#f26074",
  "error-bright": "#f26074",
  "info-strong": "#4fa8e1",
  "info-bright": "#4fa8e1",
};
