import React, { useState } from "react";
import { Link } from "expo-router";
import { ScrollView, Text, View } from "react-native";

import { Button, Card, TextInput } from "@/components";
import { ErrorState, LoadingState } from "@/components/state";
import { useSession } from "@/lib/auth";
import { describeAuthError } from "@/lib/auth/errorMessage";
import { useTheme } from "@/theme";

/**
 * US-6 — request a password reset. AC-6.1: the backend always answers 202 with the same body
 * whether or not the address exists, so this screen shows one success message unconditionally —
 * there is nothing to branch on client-side without reopening the enumeration question US-9 closes.
 */
export default function ForgotPasswordScreen(): React.JSX.Element {
  const theme = useTheme();
  const session = useSession();

  const [email, setEmail] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [submitted, setSubmitted] = useState(false);

  const canSubmit = email.trim().length > 0 && !submitting;

  async function handleSubmit(): Promise<void> {
    if (!canSubmit) {
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      await session.requestPasswordReset(email.trim());
      setSubmitted(true);
    } catch (caught) {
      setError(caught);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ScrollView
      contentContainerStyle={{
        flexGrow: 1,
        backgroundColor: theme.colors["bg"],
        padding: theme.space("space-6"),
        justifyContent: "center",
      }}
      style={{ backgroundColor: theme.colors["bg"] }}
    >
      <View style={{ width: "100%", maxWidth: 420, alignSelf: "center", gap: theme.space("space-5") }}>
        <Text
          style={{
            color: theme.colors["text-primary"],
            fontFamily: theme.resolveFontFamily("display", "semibold"),
            fontSize: theme.typeScale["2xl"].fontSize,
            lineHeight: theme.typeScale["2xl"].lineHeight,
          }}
        >
          Reset your password
        </Text>

        {submitted ? (
          <Card testID="forgot-password-success">
            <Text
              style={{
                color: theme.colors["text-primary"],
                fontFamily: theme.resolveFontFamily("body", "regular"),
                fontSize: theme.typeScale.sm.fontSize,
                lineHeight: theme.typeScale.sm.lineHeight,
              }}
            >
              If that email has an account, we&apos;ve sent a link to reset the password. It&apos;s
              valid for 60 minutes.
            </Text>
          </Card>
        ) : (
          <Card>
            <View style={{ gap: theme.space("space-4") }}>
              <TextInput
                testID="forgot-password-email"
                label="Email"
                value={email}
                onChangeText={setEmail}
                placeholder="you@example.com"
                keyboardType="email-address"
                disabled={submitting}
              />
              <Button
                testID="forgot-password-submit"
                label="Send reset link"
                onPress={() => void handleSubmit()}
                disabled={!canSubmit}
              />
            </View>
          </Card>
        )}

        {submitting ? <LoadingState title="Sending…" /> : null}

        {error && !submitting ? (
          <ErrorState
            title="Couldn't send the reset link"
            body={describeAuthError(error)}
            action={{ label: "Try again", onPress: () => void handleSubmit() }}
          />
        ) : null}

        <Link href="/login" testID="forgot-password-go-login">
          <Text
            style={{
              color: theme.colors["accent-primary-strong"],
              fontFamily: theme.resolveFontFamily("body", "semibold"),
              fontSize: theme.typeScale.sm.fontSize,
            }}
          >
            Back to log in
          </Text>
        </Link>
      </View>
    </ScrollView>
  );
}
