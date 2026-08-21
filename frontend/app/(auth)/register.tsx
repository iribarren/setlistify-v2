import React, { useState } from "react";
import { Link, useRouter } from "expo-router";
import { ScrollView, Text, View } from "react-native";

import { Button, Card, TextInput } from "@/components";
import { ErrorState, LoadingState } from "@/components/state";
import { useSession } from "@/lib/auth";
import { describeAuthError, fieldViolations } from "@/lib/auth/errorMessage";
import { useTheme } from "@/theme";

const MIN_PASSWORD_LENGTH = 12;

/**
 * US-1 — registration. AC-1.4: the password policy is stated before submission (the hint under the
 * field) as well as rendered from the 422 response if the server disagrees (a compromised-password
 * check the client can't run itself).
 */
export default function RegisterScreen(): React.JSX.Element {
  const theme = useTheme();
  const router = useRouter();
  const session = useSession();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [touchedPassword, setTouchedPassword] = useState(false);

  const violations = fieldViolations(error);
  const passwordTooShort = touchedPassword && password.length > 0 && password.length < MIN_PASSWORD_LENGTH;
  const canSubmit =
    email.trim().length > 0 && password.length >= MIN_PASSWORD_LENGTH && !submitting;

  async function handleSubmit(): Promise<void> {
    if (!canSubmit) {
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      await session.register(email.trim(), password);
      router.replace({ pathname: "/login", params: { registered: "1" } });
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
          Create an account
        </Text>

        <Card>
          <View style={{ gap: theme.space("space-4") }}>
            <TextInput
              testID="register-email"
              label="Email"
              value={email}
              onChangeText={setEmail}
              placeholder="you@example.com"
              keyboardType="email-address"
              disabled={submitting}
              errorMessage={violations.email}
            />
            <View style={{ gap: theme.space("space-2") }}>
              <TextInput
                testID="register-password"
                label="Password"
                value={password}
                onChangeText={(value) => {
                  setPassword(value);
                  setTouchedPassword(true);
                }}
                secureTextEntry
                disabled={submitting}
                errorMessage={violations.password ?? (passwordTooShort ? "At least 12 characters." : undefined)}
              />
              {!violations.password && !passwordTooShort ? (
                <Text
                  style={{
                    color: theme.colors["text-tertiary"],
                    fontFamily: theme.resolveFontFamily("body", "regular"),
                    fontSize: theme.typeScale.xs.fontSize,
                  }}
                >
                  At least 12 characters. Common or previously breached passwords are rejected.
                </Text>
              ) : null}
            </View>
            <Button
              testID="register-submit"
              label="Create account"
              onPress={() => void handleSubmit()}
              disabled={!canSubmit}
            />
          </View>
        </Card>

        {submitting ? <LoadingState title="Creating your account…" /> : null}

        {error && !submitting ? (
          <ErrorState
            title="Couldn't create your account"
            body={describeAuthError(error)}
            action={{ label: "Try again", onPress: () => void handleSubmit() }}
          />
        ) : null}

        <Link href="/login" testID="register-go-login">
          <Text
            style={{
              color: theme.colors["accent-primary-strong"],
              fontFamily: theme.resolveFontFamily("body", "semibold"),
              fontSize: theme.typeScale.sm.fontSize,
            }}
          >
            Already have an account? Log in
          </Text>
        </Link>
      </View>
    </ScrollView>
  );
}
