import React, { useEffect, useState } from "react";
import { Link, useLocalSearchParams, useRouter } from "expo-router";
import { ScrollView, Text, View } from "react-native";

import { Card } from "@/components";
import { ErrorState, LoadingState } from "@/components/state";
import { useSession } from "@/lib/auth";
import { describeAuthError } from "@/lib/auth/errorMessage";
import { useTheme } from "@/theme";

/**
 * US-7 — email verification confirm. Deliberately outside `(auth)`/`(app)` (like the health
 * screen, AC-8.5): the link in the verification email can be opened by someone signed out on this
 * device, so it must not bounce through a login redirect first. `backend/src/ApiResource/
 * EmailVerificationConfirmAction.php` documents this screen as the deep-link consumer that POSTs
 * the token — the endpoint is `POST`, not the spec's originally-written `GET`, for exactly that
 * reason (a state-mutating `GET` is a CSRF surface).
 */
export default function VerifyEmailScreen(): React.JSX.Element {
  const theme = useTheme();
  const router = useRouter();
  const session = useSession();
  const { token: tokenParam } = useLocalSearchParams<{ token?: string }>();
  const token = typeof tokenParam === "string" ? tokenParam : "";

  const [status, setStatus] = useState<"pending" | "success" | "error">("pending");
  const [error, setError] = useState<unknown>(null);

  useEffect(() => {
    if (!token) {
      return;
    }
    let cancelled = false;
    session
      .confirmEmailVerification(token)
      .then(() => {
        if (!cancelled) {
          setStatus("success");
          // The account this token belongs to may or may not be the signed-in one on this device
          // — refresh silently if there is a session; if not, this is a no-op the caller ignores.
          void session.refreshUser().catch(() => undefined);
        }
      })
      .catch((caught) => {
        if (!cancelled) {
          setError(caught);
          setStatus("error");
        }
      });
    return () => {
      cancelled = true;
    };
    // Runs once per token — session.confirmEmailVerification/refreshUser are stable callbacks.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

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
          Verify your email
        </Text>

        {!token ? (
          <ErrorState
            title="This link is missing its token"
            body="Open the verification link from your email again."
            action={{ label: "Go to login", onPress: () => router.replace("/login") }}
          />
        ) : status === "pending" ? (
          <LoadingState title="Verifying…" />
        ) : status === "success" ? (
          <Card testID="verify-email-success">
            <Text
              style={{
                color: theme.colors["text-primary"],
                fontFamily: theme.resolveFontFamily("body", "regular"),
                fontSize: theme.typeScale.sm.fontSize,
                lineHeight: theme.typeScale.sm.lineHeight,
              }}
            >
              Your email is verified.
            </Text>
          </Card>
        ) : (
          <ErrorState
            title="Couldn't verify this email"
            body={describeAuthError(error)}
            action={{ label: "Go to login", onPress: () => router.replace("/login") }}
          />
        )}

        <Link href="/login" testID="verify-email-go-login">
          <Text
            style={{
              color: theme.colors["accent-primary-strong"],
              fontFamily: theme.resolveFontFamily("body", "semibold"),
              fontSize: theme.typeScale.sm.fontSize,
            }}
          >
            Go to login
          </Text>
        </Link>
      </View>
    </ScrollView>
  );
}
