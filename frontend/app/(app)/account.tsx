import React, { useState } from "react";
import { ScrollView, Text, View } from "react-native";

import { Badge, Button, Card } from "@/components";
import { ErrorState, LoadingState } from "@/components/state";
import { useSession } from "@/lib/auth";
import { describeAuthError } from "@/lib/auth/errorMessage";
import { useTheme } from "@/theme";

/**
 * AC-2.6/AC-5.5 (frontend skeleton) + US-9 (`NavShell.dc.html`) — the Account destination: identity,
 * email verification, log out. Concerts (not Account) is the app's real home now that prompt 07 has
 * shipped the concert list.
 */
export default function AccountScreen(): React.JSX.Element {
  const theme = useTheme();
  const session = useSession();
  const [loggingOut, setLoggingOut] = useState(false);
  const [resending, setResending] = useState(false);
  const [resent, setResent] = useState(false);
  const [error, setError] = useState<unknown>(null);

  async function handleLogout(): Promise<void> {
    setLoggingOut(true);
    try {
      await session.logout();
      // AC-5.5: routing to login happens via `(app)/_layout.tsx`'s guard reacting to `status`.
    } finally {
      setLoggingOut(false);
    }
  }

  async function handleResendVerification(): Promise<void> {
    setResending(true);
    setError(null);
    try {
      await session.resendEmailVerification();
      setResent(true);
    } catch (caught) {
      setError(caught);
    } finally {
      setResending(false);
    }
  }

  if (loggingOut) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <LoadingState title="Logging out…" />
      </View>
    );
  }

  return (
    <ScrollView
      contentContainerStyle={{
        flexGrow: 1,
        backgroundColor: theme.colors["bg"],
        padding: theme.space("space-6"),
      }}
      style={{ backgroundColor: theme.colors["bg"] }}
    >
      <View style={{ width: "100%", maxWidth: 480, alignSelf: "center", gap: theme.space("space-4") }}>
        <Text
          style={{
            color: theme.colors["text-primary"],
            fontFamily: theme.resolveFontFamily("display", "semibold"),
            fontSize: theme.typeScale["2xl"].fontSize,
            lineHeight: theme.typeScale["2xl"].lineHeight,
          }}
        >
          Setlistify
        </Text>

        <Card testID="home-identity">
          <View style={{ gap: theme.space("space-2") }}>
            <Text
              style={{
                color: theme.colors["text-primary"],
                fontFamily: theme.resolveFontFamily("body", "semibold"),
                fontSize: theme.typeScale.base.fontSize,
              }}
            >
              {session.user?.email}
            </Text>
            <Badge
              label={session.user?.emailVerified ? "Verified" : "Unverified"}
              variant={session.user?.emailVerified ? "success" : "warning"}
            />
          </View>
        </Card>

        {/* AC-7.6: a non-blocking banner — verification is not required to use the app at MVP (D-19). */}
        {session.user && !session.user.emailVerified ? (
          <Card testID="verification-banner">
            <View style={{ gap: theme.space("space-3") }}>
              <Text
                style={{
                  color: theme.colors["text-secondary"],
                  fontFamily: theme.resolveFontFamily("body", "regular"),
                  fontSize: theme.typeScale.sm.fontSize,
                  lineHeight: theme.typeScale.sm.lineHeight,
                }}
              >
                Verify your email to keep your account recoverable.
              </Text>
              {resent ? (
                <Text
                  style={{
                    color: theme.colors["success-strong"],
                    fontFamily: theme.resolveFontFamily("body", "semibold"),
                    fontSize: theme.typeScale.sm.fontSize,
                  }}
                >
                  Verification email sent.
                </Text>
              ) : (
                <Button
                  testID="resend-verification"
                  label="Resend verification email"
                  variant="secondary"
                  onPress={() => void handleResendVerification()}
                  disabled={resending}
                />
              )}
            </View>
          </Card>
        ) : null}

        {resending ? <LoadingState title="Sending…" /> : null}
        {error && !resending ? (
          <ErrorState
            title="Couldn't resend the verification email"
            body={describeAuthError(error)}
            action={{ label: "Try again", onPress: () => void handleResendVerification() }}
          />
        ) : null}

        <Button testID="logout-button" label="Log out" variant="destructive" onPress={() => void handleLogout()} />
      </View>
    </ScrollView>
  );
}
