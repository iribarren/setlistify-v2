import React from "react";
import { Redirect, Stack } from "expo-router";

import { useSession } from "@/lib/auth";

/**
 * AC-8.3: the `(auth)` group holds login/register/forgot/reset. An already-authenticated visitor
 * is redirected into `(app)` — the root layout has already resolved `status` out of `"restoring"`
 * by the time this renders (`app/_layout.tsx`), so there is no flash of a login screen first.
 */
export default function AuthLayout(): React.JSX.Element {
  const { status } = useSession();

  if (status === "authenticated") {
    return <Redirect href="/home" />;
  }

  return <Stack screenOptions={{ headerShown: false }} />;
}
