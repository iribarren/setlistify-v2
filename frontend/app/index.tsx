import React from "react";
import { Redirect } from "expo-router";

/**
 * Root route. Concerts is the app's real home (prompt 07) — `(app)/_layout.tsx` bounces to
 * `/login` for an unauthenticated visitor, mirroring `(app)/index.tsx`.
 */
export default function Index(): React.JSX.Element {
  return <Redirect href="/concerts" />;
}
