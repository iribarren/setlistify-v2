import React from "react";
import { Linking, Text, View } from "react-native";

import { Button } from "@/components";
import type { PlaybackSurface, PlaylistOutput, PlaylistViewKind } from "@/lib/playlist";
import { useTheme } from "@/theme";

export interface ResultCardProps {
  kind: Extract<PlaylistViewKind, "result_full" | "result_mostly" | "result_barely" | "result_nothing">;
  job: { matchedCount?: number; lowConfidenceCount?: number; songsTotal?: number; skippedCount?: number };
  playlist: PlaylistOutput | null;
  /**
   * D-218: governs every "Open in <Provider>" action below — the caller computes this with
   * `derivePlaybackSurface()` (the only place `playbackMode` is read, AC-7.3), passing
   * `embedUnavailable: true` since this screen never mounts an embed itself. `off` (or a still-
   * loading config) resolves to `"metadata"`, which hides every open action — AC-3.2's guard.
   */
  surface: PlaybackSurface;
  providerDisplayName: string;
  onSeeReport: () => void;
  testID?: string;
}

const HITS = (job: ResultCardProps["job"]) => (job.matchedCount ?? 0) + (job.lowConfidenceCount ?? 0);
const DENOMINATOR = (job: ResultCardProps["job"]) => (job.songsTotal ?? 0) - (job.skippedCount ?? 0);

/**
 * `ResultFull/Mostly/Barely/Nothing.dc.html`. AC-4.3: only `success`/`info`/`warning`/`neutral`
 * tokens ever appear here — never `error`, never the words "error"/"failed"/"problem"/"sorry"
 * (enforced by T-6). D-171: the report's row actions aren't wired yet, so the CTA is always
 * "See what's missing", never "Review the N songs".
 */
export function ResultCard({
  kind,
  job,
  playlist,
  surface,
  providerDisplayName,
  onSeeReport,
  testID,
}: ResultCardProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const hits = HITS(job);
  const denominator = DENOMINATOR(job);
  // D-218: governed by playbackMode, not merely by whether a URL exists — `off` hides this even
  // when `playlist.externalUrl` is present.
  const externalUrl = surface.kind === "deeplink" ? surface.url : null;
  const setlistUrl = playlist?.sourceSetlists?.[0]?.url ?? null;

  const palette = kind === "result_full" ? "success" : kind === "result_barely" ? "warning" : "info";

  const headline = {
    result_full: "Every song's on the playlist",
    result_mostly: `Playlist's ready — ${denominator - hits} song${denominator - hits === 1 ? "" : "s"} need a pick`,
    result_barely: `Only ${hits} song${hits === 1 ? "" : "s"} found so far`,
    result_nothing: "None of this setlist is available",
  }[kind];

  const body = {
    result_full: "All songs from the setlist found a confident match. Nothing left to review.",
    result_mostly: "Everything else matched automatically. The rest just need a closer look.",
    result_barely: "The catalog is thin for this setlist — the playlist has what we found.",
    result_nothing: "There's no playlist to save this time — but the setlist itself is still saved to this concert.",
  }[kind];

  return (
    <View testID={testID} style={{ alignItems: "center", gap: theme.space("space-3"), padding: theme.space("space-6") }}>
      {kind !== "result_nothing" ? (
        <Text
          testID={testID ? `${testID}-count` : undefined}
          style={{
            fontFamily: theme.resolveFontFamily("mono", "bold"),
            fontSize: theme.typeScale.lg.fontSize,
            color: colors[`${palette}-strong`],
          }}
        >
          {hits} / {denominator}
        </Text>
      ) : null}
      <Text
        style={{
          color: colors["text-primary"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize: theme.typeScale.lg.fontSize,
          lineHeight: theme.typeScale.lg.lineHeight,
          textAlign: "center",
        }}
      >
        {headline}
      </Text>
      <Text
        style={{
          color: colors["text-tertiary"],
          fontFamily: theme.resolveFontFamily("body", "regular"),
          fontSize: theme.typeScale.sm.fontSize,
          textAlign: "center",
          maxWidth: 380,
        }}
      >
        {body}
      </Text>
      <View style={{ flexDirection: "row", gap: theme.space("space-3"), flexWrap: "wrap", justifyContent: "center" }}>
        {kind === "result_full" ? (
          // D-218/AC-2.4: absent — never disabled — whenever there's nothing to open, whether
          // because the URL is missing or because playbackMode governs it off.
          externalUrl ? (
            <Button
              testID={testID ? `${testID}-open` : undefined}
              label={`Open in ${providerDisplayName}`}
              onPress={() => void Linking.openURL(externalUrl)}
            />
          ) : null
        ) : (
          <>
            <Button
              testID={testID ? `${testID}-see-report` : undefined}
              label={kind === "result_nothing" ? "See the full breakdown" : "See what's missing"}
              onPress={onSeeReport}
            />
            {kind !== "result_nothing" && externalUrl ? (
              <Button
                testID={testID ? `${testID}-open-anyway` : undefined}
                label={`Open in ${providerDisplayName} anyway`}
                variant="secondary"
                onPress={() => void Linking.openURL(externalUrl)}
              />
            ) : null}
            {/* D-186/AC-2.5: absent — never disabled — when there's nothing to link to (a setlist
                cached before this branch, or an empty sourceSetlists). Pointing at a 404 would be
                worse than not offering the button at all. */}
            {kind === "result_nothing" && setlistUrl ? (
              <Button
                testID={testID ? `${testID}-view-setlist` : undefined}
                label="View the setlist"
                variant="secondary"
                onPress={() => void Linking.openURL(setlistUrl)}
              />
            ) : null}
          </>
        )}
      </View>
    </View>
  );
}
