import React, { useState } from "react";
import { Link, useLocalSearchParams, useRouter } from "expo-router";
import { ScrollView, Text, View } from "react-native";

import { Button, Card, TextInput } from "@/components";
import { ErrorState, LoadingState } from "@/components/state";
import { useSession } from "@/lib/auth";
import { describeAuthError, fieldViolations } from "@/lib/auth/errorMessage";
import { useTheme } from "@/theme";

const MIN_PASSWORD_LENGTH = 12;

/**
 * US-6 — confirm a password reset. AC-6.8: the token arrives as a query param on the deep link the
 * reset email sends (`/reset-password?token=...`) — read via Expo Router's own search params, never
 * hand-parsed from the URL.
 */
export default function ResetPasswordScreen(): React.JSX.Element {
  const theme = useTheme();
  const router = useRouter();
  const session = useSession();
  const { token: tokenParam } = useLocalSearchParams<{ token?: string }>();
  const token = typeof tokenParam === "string" ? tokenParam : "";

  const [password, setPassword] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const violations = fieldViolations(error);
  const canSubmit = token.length > 0 && password.length >= MIN_PASSWORD_LENGTH && !submitting;

  async function handleSubmit(): Promise<void> {
    if (!canSubmit) {
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      await session.confirmPasswordReset(token, password);
      // AC-6.4: this also revokes every session for the account, including this device's — send
      // the person to log in again with the new password rather than pretending they're still in.
      router.replace("/login");
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
          Choose a new password
        </Text>

        {!token ? (
          <ErrorState
            title="This link is missing its token"
            body="Open the reset link from your email again, or request a new one."
            action={{ label: "Request a new link", onPress: () => router.replace("/forgot-password") }}
          />
        ) : (
          <Card>
            <View style={{ gap: theme.space("space-4") }}>
              <TextInput
                testID="reset-password-password"
                label="New password"
                value={password}
                onChangeText={setPassword}
                secureTextEntry
                disabled={submitting}
                errorMessage={violations.password}
              />
              <Button
                testID="reset-password-submit"
                label="Reset password"
                onPress={() => void handleSubmit()}
                disabled={!canSubmit}
              />
            </View>
          </Card>
        )}

        {submitting ? <LoadingState title="Resetting your password…" /> : null}

        {error && !submitting ? (
          <ErrorState
            title="Couldn't reset your password"
            body={describeAuthError(error)}
            action={{ label: "Try again", onPress: () => void handleSubmit() }}
          />
        ) : null}

        <Link href="/login" testID="reset-password-go-login">
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
