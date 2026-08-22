import React, { useEffect, useState } from "react";
import { useLocalSearchParams, useRouter } from "expo-router";
import { Linking, Text, View } from "react-native";

import { Button, Card } from "@/components";
import { EmptyState, ErrorState, LoadingState } from "@/components/state";
// Relative, not the `@/` alias: the eslint import resolver (unlike `tsc`'s `moduleSuffixes`, and
// unlike Metro/Jest's haste resolution) only follows the `.native`/`.web` platform-suffix
// convention through a relative specifier — the same reason `components/concert/ConcertForm.tsx`
// imports `DateField` as `"../DateField"` rather than `"@/components/DateField"`.
import { linkAccount } from "../../lib/streaming/linkAccount";
import {
  SUPPORTED_PROVIDERS,
  describeStreamingError,
  providerDisplayName,
  revocationFollowUp,
  useResolveStreamingLink,
  useStartStreamingLink,
  useStreamingAccounts,
  useUnlinkStreamingAccount,
  type RevocationFollowUp,
  type StreamingAccountOutput,
  type StreamingLinkResult,
} from "@/lib/streaming";
import { useTheme } from "@/theme";

import { DisconnectConfirmation } from "./DisconnectConfirmation";
import { StreamingAccountRow } from "./StreamingAccountRow";

/**
 * `docs/specs/2026-08-22-streaming-port-and-account-linking.md` §Frontend shape — the account
 * screen's Connections section (US-1, US-2, US-3, US-5). Owns the whole platform-agnostic round
 * trip: start the link (`useStartStreamingLink`), open it (`linkAccount`, the `.web`/`.native`
 * fork), and resolve whatever opaque reference comes back (`useResolveStreamingLink`) — either
 * returned directly by `linkAccount` (native) or read off this route's own `ref` query param after a
 * full-page redirect (web, AC-1.7).
 */
export function ConnectionsSection(): React.JSX.Element {
  const theme = useTheme();
  const router = useRouter();
  const { ref: refParam } = useLocalSearchParams<{ ref?: string }>();
  const ref = typeof refParam === "string" ? refParam : undefined;

  const accountsQuery = useStreamingAccounts();
  const startLink = useStartStreamingLink();
  const resolveLink = useResolveStreamingLink();
  const unlinkAccount = useUnlinkStreamingAccount();

  const [connectingProvider, setConnectingProvider] = useState<string | null>(null);
  const [linkError, setLinkError] = useState<string | null>(null);
  const [linkOutcome, setLinkOutcome] = useState<StreamingLinkResult | null>(null);
  const [confirmingUnlink, setConfirmingUnlink] = useState<StreamingAccountOutput | null>(null);
  const [unlinkError, setUnlinkError] = useState<string | null>(null);
  const [revocationNotice, setRevocationNotice] = useState<RevocationFollowUp | null>(null);

  // AC-1.7, web return leg: the backend redirects the browser back to this route with `?ref=…`. A
  // fresh mount (the redirect is a real page load) resolves it once and strips it from the URL so a
  // later refresh of `/account` never tries to resolve an already-consumed, single-use ref (AC-8.7).
  useEffect(() => {
    if (!ref) {
      return;
    }
    resolveLink
      .mutateAsync(ref)
      .then((outcome) => setLinkOutcome(outcome))
      .catch((caught) => setLinkError(describeStreamingError(caught)))
      .finally(() => router.replace("/account"));
    // resolveLink/router are stable enough for this one-shot, ref-keyed effect; re-running on their
    // identity would risk a double resolve of a single-use reference.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ref]);

  async function handleConnect(provider: string): Promise<void> {
    setLinkError(null);
    setLinkOutcome(null);
    setConnectingProvider(provider);
    try {
      const authorizationUrl = await startLink.mutateAsync(provider);
      const result = await linkAccount(authorizationUrl);
      if (result.cancelled) {
        // AC-1.10: abandoning the flow leaves state unchanged — nothing more to do.
        return;
      }
      if (result.ref) {
        const outcome = await resolveLink.mutateAsync(result.ref);
        setLinkOutcome(outcome);
      }
    } catch (caught) {
      setLinkError(describeStreamingError(caught));
    } finally {
      setConnectingProvider(null);
    }
  }

  async function handleUnlink(account: StreamingAccountOutput): Promise<void> {
    setUnlinkError(null);
    try {
      await unlinkAccount.mutateAsync(String(account.id));
      setConfirmingUnlink(null);
      setRevocationNotice(revocationFollowUp(account.provider ?? ""));
    } catch (caught) {
      setUnlinkError(describeStreamingError(caught));
    }
  }

  const accounts = accountsQuery.data ?? [];
  const linkedProviders = new Set(accounts.map((account) => account.provider));
  const connectableProviders = SUPPORTED_PROVIDERS.filter(
    (provider) => !linkedProviders.has(provider),
  );

  return (
    <Card testID="connections-section">
      <View style={{ gap: theme.space("space-4") }}>
        <Text
          style={{
            color: theme.colors["text-primary"],
            fontFamily: theme.resolveFontFamily("display", "semibold"),
            fontSize: theme.typeScale.lg.fontSize,
            lineHeight: theme.typeScale.lg.lineHeight,
          }}
        >
          Connections
        </Text>

        {accountsQuery.isLoading ? (
          <LoadingState testID="connections-loading" title="Loading connections…" />
        ) : null}

        {accountsQuery.isError ? (
          <ErrorState
            testID="connections-error"
            title="Couldn't load your connections."
            body={describeStreamingError(accountsQuery.error)}
            action={{ label: "Try again", onPress: () => void accountsQuery.refetch() }}
          />
        ) : null}

        {!accountsQuery.isLoading && !accountsQuery.isError && accounts.length === 0 ? (
          <EmptyState
            testID="connections-empty"
            title="No streaming accounts connected yet."
            body="Connect Spotify so Setlistify can build playlists from the setlists you track."
            action={{
              label: connectingProvider === "spotify" ? "Connecting…" : "Connect Spotify",
              onPress: () => void handleConnect("spotify"),
            }}
          />
        ) : null}

        {accounts.length > 0 ? (
          <View>
            {accounts.map((account) => (
              <StreamingAccountRow
                key={account.id}
                testID={`connection-${account.provider}`}
                account={account}
                reconnecting={connectingProvider === account.provider}
                onReconnect={() => void handleConnect(account.provider ?? "")}
                onDisconnect={() => setConfirmingUnlink(account)}
              />
            ))}
          </View>
        ) : null}

        {accounts.length > 0 && connectableProviders.length > 0 ? (
          <Button
            testID="connect-another-provider"
            label={
              connectingProvider === connectableProviders[0]
                ? "Connecting…"
                : `Connect ${providerDisplayName(connectableProviders[0])}`
            }
            variant="secondary"
            onPress={() => void handleConnect(connectableProviders[0])}
            disabled={connectingProvider !== null}
          />
        ) : null}

        {linkOutcome && !linkOutcome.success ? (
          <ErrorState
            testID="link-outcome-failure"
            title={`Couldn't connect ${providerDisplayName(linkOutcome.provider) || "that account"}.`}
            body={linkOutcome.reason ?? "Please try again."}
            action={{ label: "Try again", onPress: () => void handleConnect(linkOutcome.provider) }}
          />
        ) : null}

        {linkError ? (
          <ErrorState
            testID="link-error"
            title="Couldn't complete the connection."
            body={linkError}
            action={{ label: "Dismiss", onPress: () => setLinkError(null) }}
          />
        ) : null}

        {confirmingUnlink ? (
          <DisconnectConfirmation
            testID="disconnect-confirmation"
            providerLabel={providerDisplayName(confirmingUnlink.provider ?? "")}
            disconnecting={unlinkAccount.isPending}
            onConfirm={() => void handleUnlink(confirmingUnlink)}
            onCancel={() => setConfirmingUnlink(null)}
          />
        ) : null}

        {unlinkError ? (
          <ErrorState
            testID="unlink-error"
            title="Couldn't disconnect that account."
            body={unlinkError}
            action={{ label: "Dismiss", onPress: () => setUnlinkError(null) }}
          />
        ) : null}

        {revocationNotice ? (
          <View testID="revocation-notice" style={{ gap: theme.space("space-2") }}>
            <Text
              style={{
                color: theme.colors["text-secondary"],
                fontFamily: theme.resolveFontFamily("body", "regular"),
                fontSize: theme.typeScale.sm.fontSize,
                lineHeight: theme.typeScale.sm.lineHeight,
              }}
            >
              {revocationNotice.message}
            </Text>
            <Button
              testID="revocation-notice-link"
              label="Open account settings"
              variant="secondary"
              onPress={() => void Linking.openURL(revocationNotice.url)}
            />
          </View>
        ) : null}
      </View>
    </Card>
  );
}
