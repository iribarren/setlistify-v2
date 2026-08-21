import React from "react";
import { Stack } from "expo-router";

/**
 * AC-9.3: a nested native stack for the Concerts destination — list → detail → edit push and pop
 * normally (and deep-link directly), while the persistent chrome from `(app)/_layout.tsx` (tab bar
 * on phone, sidebar on desktop) stays mounted around it.
 */
export default function ConcertsLayout(): React.JSX.Element {
  return <Stack screenOptions={{ headerShown: false }} />;
}
