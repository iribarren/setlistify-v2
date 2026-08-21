import React from "react";
import { Redirect, Stack } from "expo-router";

import { useSession } from "@/lib/auth";

/**
 * AC-8.3/AC-5.5: `(app)` holds everything requiring a session. An unauthenticated visitor —
 * including one who just logged out, or is typing a protected URL directly on web — is redirected
 * to `/login`; back navigation lands here again and is redirected again, so the protected group is
 * never reachable without a session.
 */
export default function AppLayout(): React.JSX.Element {
  const { status } = useSession();

  if (status === "unauthenticated") {
    return <Redirect href="/login" />;
  }

  return <Stack screenOptions={{ headerShown: false }} />;
}
