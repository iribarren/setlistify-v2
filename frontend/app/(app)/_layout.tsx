import React from "react";
import { Redirect, Slot } from "expo-router";
import { useWindowDimensions, View } from "react-native";

import { BottomTabBar, DESKTOP_BREAKPOINT, Sidebar } from "@/components/nav";
import { useSession } from "@/lib/auth";
import { useTheme } from "@/theme";

/**
 * AC-8.3/AC-5.5 (frontend skeleton) + US-9: `(app)` holds everything requiring a session. An
 * unauthenticated visitor is redirected to `/login`.
 *
 * AC-9.2/D-39: phone vs. desktop is ONE width breakpoint here, not a platform fork — a tablet, a
 * large phone and a resized browser window all resolve from the same `useWindowDimensions()` read.
 * `Slot` renders whichever route is active (`concerts/*` or `account`) inside the persistent chrome
 * for that width.
 */
export default function AppLayout(): React.JSX.Element {
  const { status } = useSession();
  const { width } = useWindowDimensions();
  const theme = useTheme();

  if (status === "unauthenticated") {
    return <Redirect href="/login" />;
  }

  const isDesktop = width >= DESKTOP_BREAKPOINT;

  if (isDesktop) {
    return (
      <View style={{ flex: 1, flexDirection: "row", backgroundColor: theme.colors["bg"] }}>
        <Sidebar />
        <View style={{ flex: 1 }}>
          <Slot />
        </View>
      </View>
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: theme.colors["bg"] }}>
      <View style={{ flex: 1 }}>
        <Slot />
      </View>
      <BottomTabBar />
    </View>
  );
}
