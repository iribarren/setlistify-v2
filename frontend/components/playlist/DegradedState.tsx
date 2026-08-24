import React, { useEffect, useState } from "react";
import { Text, View } from "react-native";

import { Badge, Button } from "@/components";
import { DegradedState as SharedDegradedState, ErrorState } from "@/components/state";
import type { PlaylistGenerationJobOutput, PlaylistViewKind, ProviderCandidate } from "@/lib/playlist";
import { useTheme } from "@/theme";

export type PlaylistDegradedKind = Extract<
  PlaylistViewKind,
  | "degraded_band_unknown"
  | "degraded_no_songs"
  | "blocked_budget"
  | "blocked_quota"
  | "blocked_reauth"
  | "blocked_disabled"
  | "blocked_upstream"
  | "failed_indeterminate"
  | "failed_generic"
>;

export interface PlaylistDegradedStateProps {
  kind: PlaylistDegradedKind;
  job: PlaylistGenerationJobOutput;
  providerDisplayName: string;
  alternativeProvider: ProviderCandidate | null;
  onReconnect: () => void;
  onUseAlternative: (providerKey: string) => void;
  onRetry: () => void;
  onCreateAnyway: () => void;
  onCheckAgain: () => void;
  testID?: string;
}

function useCountdown(target: string | null | undefined): string | null {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (!target) {
      return;
    }
    const interval = setInterval(() => setNow(Date.now()), 60_000);
    return () => clearInterval(interval);
  }, [target]);

  if (!target) {
    return null;
  }
  const ms = new Date(target).getTime() - now;
  if (Number.isNaN(ms) || ms <= 0) {
    return "shortly";
  }
  const hours = Math.floor(ms / 3_600_000);
  const minutes = Math.floor((ms % 3_600_000) / 60_000);
  return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
}

/**
 * `Degraded*.dc.html` (six designed states) plus the two genuine `failed` screens. AC-6.1: driven
 * entirely by the server field the caller already narrowed via `derivePlaylistView` — never an HTTP
 * status, never a string match. AC-6.2/AC-6.3: no retry on the automatic-recovery states.
 */
export function PlaylistDegradedState({
  kind,
  job,
  providerDisplayName,
  alternativeProvider,
  onReconnect,
  onUseAlternative,
  onRetry,
  onCreateAnyway,
  onCheckAgain,
  testID,
}: PlaylistDegradedStateProps): React.JSX.Element {
  const theme = useTheme();
  const countdown = useCountdown(job.resumableAfter);
  const matched = job.matchedCount ?? 0;
  const total = job.songsTotal ?? 0;

  if (kind === "failed_generic") {
    return (
      <ErrorState
        testID={testID}
        title="This generation didn't complete."
        body="Something interrupted it. Trying again starts a fresh attempt from where it's safe to resume."
        action={{ label: "Try again", onPress: onRetry }}
      />
    );
  }

  if (kind === "failed_indeterminate") {
    return (
      <View testID={testID} style={{ alignItems: "center", gap: theme.space("space-3"), padding: theme.space("space-6") }}>
        <Text
          style={{
            color: theme.colors["text-primary"],
            fontFamily: theme.resolveFontFamily("display", "semibold"),
            fontSize: theme.typeScale.lg.fontSize,
            textAlign: "center",
          }}
        >
          We may have already created this playlist
        </Text>
        <Text
          style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.sm.fontSize, textAlign: "center", maxWidth: 380 }}
        >
          We may have created an empty playlist in your {providerDisplayName} account. We won&apos;t create
          another unless you tell us to.
        </Text>
        <Button testID={testID ? `${testID}-create-anyway` : undefined} label="Create it anyway" onPress={onCreateAnyway} />
      </View>
    );
  }

  if (kind === "blocked_reauth") {
    return (
      <View testID={testID} style={{ alignItems: "center", gap: theme.space("space-3"), padding: theme.space("space-6") }}>
        <Text
          style={{ color: theme.colors["text-primary"], fontFamily: theme.resolveFontFamily("display", "semibold"), fontSize: theme.typeScale.lg.fontSize, textAlign: "center" }}
        >
          Your {providerDisplayName} connection needs a refresh
        </Text>
        <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.sm.fontSize, textAlign: "center", maxWidth: 380 }}>
          Reconnect and we&apos;ll pick up the playlist right where we left off.
        </Text>
        <Badge testID={testID ? `${testID}-badge` : undefined} label="Needs reconnect" variant="warning" />
        <Button testID={testID ? `${testID}-reconnect` : undefined} label={`Reconnect ${providerDisplayName}`} onPress={onReconnect} />
      </View>
    );
  }

  if (kind === "blocked_disabled") {
    return (
      <View testID={testID} style={{ alignItems: "center", gap: theme.space("space-3"), padding: theme.space("space-6") }}>
        <Text
          style={{ color: theme.colors["text-primary"], fontFamily: theme.resolveFontFamily("display", "semibold"), fontSize: theme.typeScale.lg.fontSize, textAlign: "center" }}
        >
          {providerDisplayName} playlists are paused for now
        </Text>
        <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.sm.fontSize, textAlign: "center", maxWidth: 380 }}>
          This isn&apos;t about your account — it affects everyone, and it&apos;s back as soon as we lift it.
        </Text>
        {alternativeProvider ? (
          <Button
            testID={testID ? `${testID}-alternative` : undefined}
            label={`Use ${alternativeProvider.displayName} instead`}
            onPress={() => onUseAlternative(alternativeProvider.key)}
          />
        ) : (
          <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>
            We&apos;ll pick this back up automatically once it&apos;s re-enabled.
          </Text>
        )}
      </View>
    );
  }

  if (kind === "degraded_band_unknown") {
    return (
      <SharedDegradedState
        testID={testID}
        title="Can't find this band on setlist.fm"
        body="This band isn't in setlist.fm's database yet — that happens with smaller or newer acts. There's no setlist for Setlistify to build from right now."
        progress={{ completed: 0, total: 0 }}
      />
    );
  }

  if (kind === "degraded_no_songs") {
    return (
      <View testID={testID} style={{ alignItems: "center", gap: theme.space("space-3"), padding: theme.space("space-6") }}>
        <Text
          testID={testID ? `${testID}-known-badge` : undefined}
          style={{ fontFamily: theme.resolveFontFamily("mono", "medium"), fontSize: theme.typeScale.xs.fontSize, color: theme.colors["text-tertiary"] }}
        >
          Known on setlist.fm
        </Text>
        <Text
          style={{ color: theme.colors["text-primary"], fontFamily: theme.resolveFontFamily("display", "semibold"), fontSize: theme.typeScale.lg.fontSize, textAlign: "center" }}
        >
          No setlist logged for this show yet
        </Text>
        <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.sm.fontSize, textAlign: "center", maxWidth: 380 }}>
          Fan-submitted setlists often appear within a few days of the show.
        </Text>
        <Button testID={testID ? `${testID}-check-again` : undefined} label="Check again" onPress={onCheckAgain} />
      </View>
    );
  }

  if (kind === "blocked_budget") {
    return (
      <View testID={testID} style={{ alignItems: "center", gap: theme.space("space-3"), padding: theme.space("space-6") }}>
        <Text
          style={{ color: theme.colors["text-primary"], fontFamily: theme.resolveFontFamily("display", "semibold"), fontSize: theme.typeScale.lg.fontSize, textAlign: "center" }}
        >
          We&apos;ve used up today&apos;s setlist.fm lookups
        </Text>
        <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.sm.fontSize, textAlign: "center", maxWidth: 380 }}>
          Setlistify shares one lookup budget across every user, and it&apos;s spent for today. Nothing about
          your concert is lost — this just picks back up automatically.
        </Text>
        {countdown ? (
          <Text
            testID={testID ? `${testID}-countdown` : undefined}
            style={{ fontFamily: theme.resolveFontFamily("mono", "medium"), fontSize: theme.typeScale.sm.fontSize, color: theme.colors["info-strong"] }}
          >
            Resets in {countdown}
          </Text>
        ) : null}
      </View>
    );
  }

  // blocked_quota / blocked_upstream — same "matched so far, saved, no retry" framing (D-170).
  return (
    <View testID={testID} style={{ alignItems: "center", gap: theme.space("space-3"), padding: theme.space("space-6") }}>
      <Text
        style={{ color: theme.colors["text-primary"], fontFamily: theme.resolveFontFamily("display", "semibold"), fontSize: theme.typeScale.lg.fontSize, textAlign: "center" }}
      >
        {kind === "blocked_upstream"
          ? `We're having trouble reaching ${providerDisplayName}`
          : `${providerDisplayName}'s asking us to slow down`}
      </Text>
      <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.sm.fontSize, textAlign: "center", maxWidth: 380 }}>
        {total > 0
          ? `We matched ${matched} of ${total} songs before this happened. Your progress is saved — the rest will match automatically.`
          : "Your progress is saved — the rest will match automatically."}
      </Text>
      {total > 0 ? (
        <Text
          testID={testID ? `${testID}-saved-count` : undefined}
          style={{ fontFamily: theme.resolveFontFamily("mono", "medium"), fontSize: theme.typeScale.sm.fontSize, color: theme.colors["info-strong"] }}
        >
          {matched} / {total} saved
        </Text>
      ) : null}
    </View>
  );
}
