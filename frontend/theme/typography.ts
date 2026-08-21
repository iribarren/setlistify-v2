import { Platform } from "react-native";

/**
 * Typography tokens transcribed from `docs/design/canvas/Typography.dc.html` ("02 · Typography").
 *
 * Three families, one role each (D-13, bundled via `expo-font` + `@expo-google-fonts/*`, not the
 * canvas's web-only Google Fonts `<link>`):
 * - `display` (Petrona) — concert names, section headers, hero moments.
 * - `body` (Manrope) — everything functional: buttons, labels, rows, fields, body copy.
 * - `mono` (Space Mono) — dates, prices, timestamps, match-count badges. Sparingly, never body copy.
 *
 * The type scale (size/line-height/weight) is family-agnostic — a screen picks the family that
 * fits the content (Components.dc.html mixes families within a single screen), so `fontFamilies`
 * and `typeScale` are exposed separately rather than baked together per step.
 */

export type FontWeightKey = "regular" | "medium" | "semibold" | "bold" | "extrabold";

interface FontFamilyDefinition {
  /** Keys are the `useFonts()` keys registered in `app/_layout.tsx` (expo-font). */
  weights: Partial<Record<FontWeightKey, string>>;
  italic?: string;
  /** The canvas's CSS fallback stack, kept per D-13 so the app stays legible before fonts load. */
  fallbackStack: string;
}

const families: Record<"display" | "body" | "mono", FontFamilyDefinition> = {
  display: {
    weights: {
      regular: "Petrona_400Regular",
      semibold: "Petrona_600SemiBold",
    },
    italic: "Petrona_400Regular_Italic",
    fallbackStack: "ui-serif, Georgia, 'Times New Roman', serif",
  },
  body: {
    weights: {
      regular: "Manrope_400Regular",
      medium: "Manrope_500Medium",
      semibold: "Manrope_600SemiBold",
      bold: "Manrope_700Bold",
      extrabold: "Manrope_800ExtraBold",
    },
    fallbackStack: "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
  },
  mono: {
    weights: {
      regular: "SpaceMono_400Regular",
      bold: "SpaceMono_700Bold",
    },
    fallbackStack: "ui-monospace, 'SFMono-Regular', Menlo, monospace",
  },
};

export type FontFamilyRole = keyof typeof families;
export const fontFamilies = families;

/**
 * Resolves a loaded font family to a platform-correct RN `fontFamily` value. On web, the loaded
 * name is prefixed to the canvas's fallback stack (a real CSS font-family list) so text stays
 * legible in the flash before the webfont paints. On iOS/Android, RN's `fontFamily` takes a single
 * PostScript name — there is no stack — so only the loaded name is used; the OS falls back to its
 * own default automatically if the bundled font has not registered yet (D-13/R-9).
 */
export function resolveFontFamily(role: FontFamilyRole, weight: FontWeightKey, italic = false): string {
  const def = families[role];
  const loaded = (italic ? def.italic : undefined) ?? def.weights[weight] ?? def.weights.regular;
  if (!loaded) {
    throw new Error(`No loaded font registered for ${role}/${weight}`);
  }
  return Platform.OS === "web" ? `${loaded}, ${def.fallbackStack}` : loaded;
}

/** Every `useFonts()` entry the app must load (D-13 — only the weights the scale actually uses). */
export const fontsToLoad = {
  Petrona_400Regular: require("@expo-google-fonts/petrona/400Regular/Petrona_400Regular.ttf"),
  Petrona_400Regular_Italic: require("@expo-google-fonts/petrona/400Regular_Italic/Petrona_400Regular_Italic.ttf"),
  Petrona_600SemiBold: require("@expo-google-fonts/petrona/600SemiBold/Petrona_600SemiBold.ttf"),
  Manrope_400Regular: require("@expo-google-fonts/manrope/400Regular/Manrope_400Regular.ttf"),
  Manrope_500Medium: require("@expo-google-fonts/manrope/500Medium/Manrope_500Medium.ttf"),
  Manrope_600SemiBold: require("@expo-google-fonts/manrope/600SemiBold/Manrope_600SemiBold.ttf"),
  Manrope_700Bold: require("@expo-google-fonts/manrope/700Bold/Manrope_700Bold.ttf"),
  Manrope_800ExtraBold: require("@expo-google-fonts/manrope/800ExtraBold/Manrope_800ExtraBold.ttf"),
  SpaceMono_400Regular: require("@expo-google-fonts/space-mono/400Regular/SpaceMono_400Regular.ttf"),
  SpaceMono_700Bold: require("@expo-google-fonts/space-mono/700Bold/SpaceMono_700Bold.ttf"),
};

export type TypeScaleToken = "display" | "3xl" | "2xl" | "xl" | "lg" | "base" | "sm" | "xs";

export interface TypeScaleEntry {
  fontSize: number;
  lineHeight: number;
  weight: FontWeightKey;
}

// Typography.dc.html, "Type scale" — size / line-height · weight, verbatim.
export const typeScale: Record<TypeScaleToken, TypeScaleEntry> = {
  display: { fontSize: 44, lineHeight: 52, weight: "semibold" },
  "3xl": { fontSize: 32, lineHeight: 40, weight: "semibold" },
  "2xl": { fontSize: 26, lineHeight: 34, weight: "semibold" },
  xl: { fontSize: 21, lineHeight: 28, weight: "semibold" },
  lg: { fontSize: 18, lineHeight: 26, weight: "medium" },
  base: { fontSize: 16, lineHeight: 24, weight: "regular" },
  sm: { fontSize: 14, lineHeight: 20, weight: "regular" },
  xs: { fontSize: 12, lineHeight: 16, weight: "semibold" },
};
