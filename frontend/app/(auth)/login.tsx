import React, { useState } from "react";
import { Link, useLocalSearchParams } from "expo-router";
import { ScrollView, Text, View } from "react-native";

import { Button, Card, TextInput } from "@/components";
import { ErrorState, LoadingState } from "@/components/state";
import { useSession } from "@/lib/auth";
import { describeAuthError, fieldViolations } from "@/lib/auth/errorMessage";
import { useTheme } from "@/theme";

/**
 * US-2 — login. AC-2.4: a wrong password, unknown email, unverified/disabled account all fail
 * identically — this screen never tries to tell them apart, it just renders whatever generic
 * message the backend sent.
 */
export default function LoginScreen(): React.JSX.Element {
  const theme = useTheme();
  const session = useSession();
  const { registered } = useLocalSearchParams<{ registered?: string }>();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const violations = fieldViolations(error);
  const canSubmit = email.trim().length > 0 && password.length > 0 && !submitting;

  async function handleSubmit(): Promise<void> {
    if (!canSubmit) {
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      await session.login(email.trim(), password);
      // AC-2.6: routing into the protected group happens via `(app)/_layout.tsx`'s guard reacting
      // to `status` — no imperative navigation needed here.
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
          Log in
        </Text>

        {registered ? (
          <Card testID="registration-success-banner">
            <Text
              style={{
                color: theme.colors["text-primary"],
                fontFamily: theme.resolveFontFamily("body", "regular"),
                fontSize: theme.typeScale.sm.fontSize,
                lineHeight: theme.typeScale.sm.lineHeight,
              }}
            >
              Account created. Check your email to verify it, then log in below.
            </Text>
          </Card>
        ) : null}

        <Card>
          <View style={{ gap: theme.space("space-4") }}>
            <TextInput
              testID="login-email"
              label="Email"
              value={email}
              onChangeText={setEmail}
              placeholder="you@example.com"
              keyboardType="email-address"
              disabled={submitting}
              errorMessage={violations.email}
            />
            <TextInput
              testID="login-password"
              label="Password"
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              disabled={submitting}
              errorMessage={violations.password}
            />
            <Button
              testID="login-submit"
              label="Log in"
              onPress={() => void handleSubmit()}
              disabled={!canSubmit}
            />
          </View>
        </Card>

        {submitting ? <LoadingState title="Logging in…" /> : null}

        {error && !submitting ? (
          <ErrorState
            title="Couldn't log you in"
            body={describeAuthError(error)}
            action={{ label: "Try again", onPress: () => void handleSubmit() }}
          />
        ) : null}

        <View style={{ flexDirection: "row", justifyContent: "space-between" }}>
          <Link href="/register" testID="login-go-register">
            <Text
              style={{
                color: theme.colors["accent-primary-strong"],
                fontFamily: theme.resolveFontFamily("body", "semibold"),
                fontSize: theme.typeScale.sm.fontSize,
              }}
            >
              Create an account
            </Text>
          </Link>
          <Link href="/forgot-password" testID="login-go-forgot">
            <Text
              style={{
                color: theme.colors["accent-primary-strong"],
                fontFamily: theme.resolveFontFamily("body", "semibold"),
                fontSize: theme.typeScale.sm.fontSize,
              }}
            >
              Forgot password?
            </Text>
          </Link>
        </View>
      </View>
    </ScrollView>
  );
}
