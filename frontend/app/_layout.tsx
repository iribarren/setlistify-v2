import React, { useEffect, useState } from "react";
import { useFonts } from "expo-font";
import * as SplashScreen from "expo-splash-screen";
import { Stack } from "expo-router";
import { StatusBar } from "expo-status-bar";
import { QueryClientProvider } from "@tanstack/react-query";

import { createAppQueryClient } from "@/lib/api";
import { ThemeProvider, fontsToLoad, useTheme } from "@/theme";

SplashScreen.preventAutoHideAsync().catch(() => {
  // No-op — if this fails (e.g. already hidden), the splash screen was never going to block us.
});

const queryClient = createAppQueryClient();

/**
 * Root layout (US-1/US-8): loads the design-system fonts (D-13), then mounts `ThemeProvider`
 * (US-2/US-3) and `QueryClientProvider` (AC-8.1) around Expo Router's `Stack`. Nothing renders
 * until fonts are ready, so there is no unstyled-text flash on native.
 */
export default function RootLayout(): React.JSX.Element | null {
  const [fontsLoaded, fontError] = useFonts(fontsToLoad);
  const [splashHidden, setSplashHidden] = useState(false);

  useEffect(() => {
    if (fontsLoaded || fontError) {
      SplashScreen.hideAsync()
        .catch(() => undefined)
        .finally(() => setSplashHidden(true));
    }
  }, [fontsLoaded, fontError]);

  if (!fontsLoaded && !fontError) {
    return null;
  }
  if (!splashHidden) {
    return null;
  }

  return (
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <ThemedStack />
      </QueryClientProvider>
    </ThemeProvider>
  );
}

function ThemedStack(): React.JSX.Element {
  const { colors, scheme } = useTheme();
  return (
    <>
      <StatusBar style={scheme === "dark" ? "light" : "dark"} />
      <Stack
        screenOptions={{
          headerStyle: { backgroundColor: colors["surface-raised"] },
          headerTintColor: colors["text-primary"],
          contentStyle: { backgroundColor: colors["bg"] },
        }}
      />
    </>
  );
}
