import React, { useEffect, useRef, useState } from "react";
import { Linking, Text, View } from "react-native";

import { Button, Card } from "@/components";
import { derivePlaybackSurface, EMBED_LOAD_TIMEOUT_MS, type PlaylistOutput, type ProviderConfigOutput } from "@/lib/playlist";
import { useTheme } from "@/theme";

// Relative, not `@/` — the eslint import resolver only follows the platform-suffix convention
// through a relative specifier (see frontend/README.md, streaming_account_linking's linkAccount.*).
import { PlaybackEmbed } from "./PlaybackEmbed";

export interface PlaybackPanelProps {
  playlist: PlaylistOutput;
  /** `useProviderConfigs()`'s data, passed down — no second fetch, no second cache entry (D-220). */
  providers: ProviderConfigOutput[] | undefined;
  testID?: string;
}

/**
 * Replaces the `reserved-playback` placeholder (D-176), directly beneath the tracklist card
 * (AC-1.5/D-221 — this component never touches it). Chooses embed / deep-link / nothing per
 * `derivePlaybackSurface()` and owns the one piece of local state the fallback needs
 * (`embedUnavailable`, D-214) — one way within a mount (AC-5.4), reset only when a different
 * playlist is shown.
 */
export function PlaybackPanel({ playlist, providers, testID }: PlaybackPanelProps): React.JSX.Element | null {
  const theme = useTheme();
  const [embedUnavailable, setEmbedUnavailable] = useState(false);
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // D-214/AC-5.4: sticky within a mount, but a genuinely different playlist (delete + regenerate
  // without the section remounting) starts fresh. React's documented pattern for adjusting state
  // from a prop change during render, not an effect (frontend's react-hooks/set-state-in-effect rule).
  const [trackedPlaylistId, setTrackedPlaylistId] = useState(playlist.id);
  if (trackedPlaylistId !== playlist.id) {
    setTrackedPlaylistId(playlist.id);
    setEmbedUnavailable(false);
  }

  const provider = providers?.find((candidate) => candidate.key === playlist.provider);
  const displayName = provider?.displayName ?? "your provider";
  const surface = derivePlaybackSurface({ provider, playlist, embedUnavailable });

  useEffect(() => {
    if (surface.kind !== "embed") {
      return;
    }
    const timeout = setTimeout(() => setEmbedUnavailable(true), EMBED_LOAD_TIMEOUT_MS);
    timeoutRef.current = timeout;
    return () => clearTimeout(timeout);
    // Re-armed whenever we (re-)enter the embed surface for this playlist; `surface.embedUrl`
    // captures both "a different playlist" and "the url itself changed" in one dependency.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [surface.kind === "embed" ? surface.embedUrl : null]);

  function handleLoad(): void {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
      timeoutRef.current = null;
    }
  }

  function handleUnavailable(): void {
    setEmbedUnavailable(true);
  }

  if (surface.kind === "metadata") {
    // AC-3.1/AC-5.5: no embed, no open action, no disabled button, no dashed placeholder, no empty
    // region — achieved by rendering nothing at all. The tracklist above already carries the
    // playlist's metadata (AC-1.5).
    return null;
  }

  if (surface.kind === "deeplink") {
    return (
      <Card testID={testID}>
        <View style={{ gap: theme.space("space-3") }}>
          <Button
            testID={testID ? `${testID}-open` : undefined}
            label={`Open in ${displayName}`}
            // D-217: https handoff only — no custom scheme, no canOpenURL.
            onPress={() => void Linking.openURL(surface.url)}
          />
        </View>
      </Card>
    );
  }

  return (
    <Card testID={testID}>
      <View style={{ gap: theme.space("space-3") }}>
        <PlaybackEmbed url={surface.embedUrl} onLoad={handleLoad} onUnavailable={handleUnavailable} />
        {/* AC-1.4/D-222: provider-neutral, keyed off displayName, secondary text, never an error colour. */}
        <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>
          Playback here depends on your {displayName} account; you may hear previews rather than full tracks.
        </Text>
      </View>
    </Card>
  );
}
