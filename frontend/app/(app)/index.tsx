import React from "react";
import { Redirect } from "expo-router";

/** The authenticated shell's default route — Concerts is the app's real home now (prompt 07). */
export default function AppIndex(): React.JSX.Element {
  return <Redirect href="/concerts" />;
}
