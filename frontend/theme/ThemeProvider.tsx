import React, { createContext, useContext, useMemo, type ReactNode } from "react";
import { useColorScheme } from "react-native";

import { lightColors, darkColors, type ColorTokens } from "./colors";
import { getElevationStyle, type ElevationToken } from "./elevation";
import { radius, type RadiusToken } from "./radius";
import { spacing, type SpaceToken } from "./spacing";
import {
  fontFamilies,
  resolveFontFamily,
  typeScale,
  type FontFamilyRole,
  type FontWeightKey,
  type TypeScaleToken,
} from "./typography";

export type ColorScheme = "light" | "dark";

export interface Theme {
  scheme: ColorScheme;
  colors: ColorTokens;
  spacing: typeof spacing;
  radius: typeof radius;
  typeScale: typeof typeScale;
  fontFamilies: typeof fontFamilies;
  resolveFontFamily: (role: FontFamilyRole, weight: FontWeightKey, italic?: boolean) => string;
  getElevationStyle: (level: ElevationToken) => ReturnType<typeof getElevationStyle>;
  space: (token: SpaceToken) => number;
  rad: (token: RadiusToken) => number;
}

const ThemeContext = createContext<Theme | null>(null);

/**
 * AC-3.1/AC-3.2: resolves from the OS color scheme via `useColorScheme()` and re-renders whenever
 * it changes — no restart, no in-app toggle (AC-3.6, US-3). This is the ONE place light/dark
 * resolution happens (AC-2.3) — every consumer reads tokens through `useTheme()`, never a fixed
 * palette import.
 */
export function ThemeProvider({ children }: { children: ReactNode }): React.JSX.Element {
  const osScheme = useColorScheme();
  const scheme: ColorScheme = osScheme === "dark" ? "dark" : "light";

  const theme = useMemo<Theme>(
    () => ({
      scheme,
      colors: scheme === "dark" ? darkColors : lightColors,
      spacing,
      radius,
      typeScale,
      fontFamilies,
      resolveFontFamily,
      getElevationStyle,
      space: (token: SpaceToken) => spacing[token],
      rad: (token: RadiusToken) => radius[token],
    }),
    [scheme],
  );

  return <ThemeContext.Provider value={theme}>{children}</ThemeContext.Provider>;
}

export function useTheme(): Theme {
  const theme = useContext(ThemeContext);
  if (!theme) {
    throw new Error("useTheme() must be called within a ThemeProvider (see app/_layout.tsx)");
  }
  return theme;
}

export type { TypeScaleToken };
