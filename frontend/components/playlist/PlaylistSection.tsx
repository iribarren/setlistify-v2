import React, { useState } from "react";
import { useRouter } from "expo-router";
import { Text, View } from "react-native";

import { Badge, Button, Card } from "@/components";
import { useStreamingAccounts } from "@/lib/streaming";
import {
  chooseProvider,
  derivePlaylistView,
  pickCurrentJob,
  selectProviderCandidates,
  useCancelGeneration,
  useConcertPlaylistJobs,
  useConcertPlaylists,
  useDeletePlaylist,
  useProviderConfigs,
  useStartGeneration,
  type ProviderConfigOutput,
} from "@/lib/playlist";
import { useTheme } from "@/theme";

import { DeletePlaylistConfirmation } from "./DeletePlaylistConfirmation";
import { GenerateTrigger } from "./GenerateTrigger";
import { ModeSheet } from "./ModeSheet";
import { PlaybackPanel } from "./PlaybackPanel";
import { ResumeBanner } from "./ResumeBanner";

export interface PlaylistSectionProps {
  concertId: string;
  testID?: string;
}

function displayNameFor(providers: ProviderConfigOutput[] | undefined, key: string | null | undefined): string {
  return providers?.find((provider) => provider.key === key)?.displayName ?? "your provider";
}

/**
 * `ConcertPlaylist.dc.html` / `Main.dc.html` — the concert detail screen's Playlist section (AC-1.1,
 * AC-8.1). Replaces the `reserved-playlist` placeholder (D-176). Idle → the trigger; a live/blocked/
 * failed job with no playlist yet → a compact status linking into `/playlist`; a playlist → the
 * tracklist card with the match badge and a `reserved-playback` placeholder beneath it (AC-8.3).
 */
export function PlaylistSection({ concertId, testID }: PlaylistSectionProps): React.JSX.Element {
  const theme = useTheme();
  const router = useRouter();
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const [choosingMode, setChoosingMode] = useState(false);

  const providersQuery = useProviderConfigs();
  const accountsQuery = useStreamingAccounts();
  const jobsQuery = useConcertPlaylistJobs(concertId);
  const playlistsQuery = useConcertPlaylists(concertId);
  const startGeneration = useStartGeneration();
  const deletePlaylist = useDeletePlaylist(concertId);
  // Computed ahead of the loading early-return so this hook is always called unconditionally.
  const jobForCancel = pickCurrentJob(jobsQuery.data ?? []);
  const cancelGeneration = useCancelGeneration(jobForCancel?.id ? String(jobForCancel.id) : "");

  if (jobsQuery.isLoading || playlistsQuery.isLoading) {
    return (
      <Card testID={testID ? `${testID}-loading` : undefined}>
        <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.sm.fontSize }}>Loading playlist…</Text>
      </Card>
    );
  }

  const currentJob = pickCurrentJob(jobsQuery.data ?? []);
  const playlist = (playlistsQuery.data ?? [])[0] ?? null;
  const view = derivePlaylistView(currentJob, playlist, providersQuery.data, accountsQuery.data);

  const providerName = displayNameFor(providersQuery.data, playlist?.provider ?? currentJob?.provider);

  async function handleGenerate(provider?: string, mode?: "fast" | "normal"): Promise<void> {
    await startGeneration.mutateAsync({ concertId, provider, mode });
    router.push(`/concerts/${concertId}/playlist`);
  }

  async function handleStartOver(): Promise<void> {
    if (!currentJob?.id) {
      return;
    }
    // D-208: cancel the suspended job, then create a fresh one covering the same (concert, provider) —
    // never a server-side reset, so D-129's partial unique index stays obviously satisfied.
    await cancelGeneration.mutateAsync();
    await handleGenerate(currentJob.provider ?? undefined, currentJob.mode === "normal" ? "normal" : undefined);
  }

  async function handleDelete(): Promise<void> {
    if (!playlist?.id) {
      return;
    }
    await deletePlaylist.mutateAsync(String(playlist.id));
    setConfirmingDelete(false);
  }

  // A result/degraded view with no playlist behind it (e.g. the playlist was just deleted — AC-7.4
  // — while its originating job record is still there) reads as "nothing to show" too: the trigger,
  // not a stale status card pointing at a playlist that no longer exists.
  const hasLiveOrRecoverableJob =
    view.kind === "progress" ||
    view.kind === "choose_setlist" ||
    view.kind === "choose_versions" ||
    view.kind === "expired" ||
    view.kind.startsWith("blocked") ||
    view.kind === "failed_generic" ||
    view.kind === "failed_indeterminate";

  if (!playlist && !hasLiveOrRecoverableJob) {
    const candidates = selectProviderCandidates(providersQuery.data, accountsQuery.data);
    const choice = chooseProvider(candidates);
    return (
      <Card testID={testID}>
        <View style={{ gap: theme.space("space-3") }}>
          <Text style={{ color: theme.colors["text-primary"], fontFamily: theme.resolveFontFamily("display", "semibold"), fontSize: theme.typeScale.base.fontSize }}>
            Playlist
          </Text>
          <GenerateTrigger
            testID="generate-playlist-button"
            hasCandidates={choice.kind !== "none"}
            choiceCandidates={choice.kind === "choice" ? choice.candidates : null}
            generating={startGeneration.isPending}
            onGenerate={(provider) => void handleGenerate(provider)}
            onLinkAccount={() => router.push("/account")}
          />
          {/* D-203: closes spec 16's Q-2 — "Generate playlist" stays the one-tap default. */}
          {choice.kind !== "none" && !choosingMode ? (
            <Button
              testID="playlist-choose-yourself-link"
              label="Or choose it yourself →"
              variant="secondary"
              onPress={() => setChoosingMode(true)}
            />
          ) : null}
          {choosingMode ? (
            <ModeSheet
              testID="playlist-mode-sheet"
              generating={startGeneration.isPending}
              onSelectFast={() => void handleGenerate(undefined, "fast")}
              onSelectChooseYourself={() => void handleGenerate(undefined, "normal")}
              onDismiss={() => setChoosingMode(false)}
            />
          ) : null}
        </View>
      </Card>
    );
  }

  if (!playlist) {
    // A job exists (in progress, awaiting a choice, blocked, expired, or failed/expired) but no
    // playlist yet. D-207: a suspended Normal-mode job gets the resume banner IN PLACE of a generic
    // status card — no inbox, reopening the concert IS the re-entry path.
    if ((view.kind === "choose_setlist" || view.kind === "choose_versions") && currentJob) {
      return (
        <ResumeBanner
          testID="playlist-resume-banner"
          job={currentJob}
          onResume={() => router.push(`/concerts/${concertId}/playlist`)}
          onStartOver={() => void handleStartOver()}
          startingOver={cancelGeneration.isPending || startGeneration.isPending}
        />
      );
    }

    const label =
      view.kind === "progress"
        ? "Generating your playlist…"
        : view.kind === "expired"
          ? "Your paused session lapsed"
          : view.kind.startsWith("blocked")
            ? "Playlist generation is paused"
            : view.kind.startsWith("failed")
              ? "Playlist generation didn't complete"
              : "Playlist";

    return (
      <Card testID={testID}>
        <View style={{ gap: theme.space("space-3") }}>
          <Text style={{ color: theme.colors["text-primary"], fontFamily: theme.resolveFontFamily("display", "semibold"), fontSize: theme.typeScale.base.fontSize }}>
            {label}
          </Text>
          <Button
            testID="view-playlist-progress"
            label={view.kind === "expired" ? "See what changed" : "View progress"}
            variant="secondary"
            onPress={() => router.push(`/concerts/${concertId}/playlist`)}
          />
        </View>
      </Card>
    );
  }

  // A playlist exists — the permanent, match-state-carrying card (AC-8.2/AC-8.4).
  const tracks = playlist.tracks ?? [];
  const preview = tracks.slice(0, 4);
  const remaining = tracks.length - preview.length;
  const matched = (currentJob?.matchedCount ?? 0) + (currentJob?.lowConfidenceCount ?? 0);
  const total = currentJob?.songsTotal ?? tracks.length;
  const isPartial = matched < total;

  return (
    <View testID={testID} style={{ gap: theme.space("space-3") }}>
      <View style={{ flexDirection: "row", alignItems: "center", justifyContent: "space-between" }}>
        <Text style={{ color: theme.colors["text-primary"], fontFamily: theme.resolveFontFamily("display", "semibold"), fontSize: theme.typeScale.base.fontSize }}>
          Playlist
        </Text>
        {isPartial ? (
          <Badge testID="playlist-match-badge" label={`${matched} of ${total} matched`} variant="info" />
        ) : null}
      </View>
      <Card>
        <View style={{ gap: theme.space("space-3") }}>
          <Text style={{ color: theme.colors["text-primary"], fontFamily: theme.resolveFontFamily("display", "semibold"), fontSize: theme.typeScale.base.fontSize }}>
            {playlist.name}
          </Text>
          <View style={{ gap: theme.space("space-1") }}>
            {preview.map((track, index) => (
              <Text key={`${track.ordinal}-${index}`} style={{ color: theme.colors["text-secondary"], fontSize: theme.typeScale.sm.fontSize }}>
                {track.sourceTitle}
              </Text>
            ))}
          </View>
          {remaining > 0 ? (
            <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>+ {remaining} more tracks</Text>
          ) : null}
          <View style={{ flexDirection: "row", gap: theme.space("space-2") }}>
            {isPartial ? (
              <Button
                testID="playlist-see-report"
                label="See what's missing"
                variant="secondary"
                onPress={() => router.push(`/concerts/${concertId}/playlist-report`)}
              />
            ) : null}
            <Button testID="playlist-delete" label="Delete" variant="destructive" onPress={() => setConfirmingDelete(true)} />
          </View>
        </View>
      </Card>

      {confirmingDelete ? (
        <DeletePlaylistConfirmation
          testID="delete-playlist-confirmation"
          providerDisplayName={providerName}
          deleting={deletePlaylist.isPending}
          onConfirm={() => void handleDelete()}
          onCancel={() => setConfirmingDelete(false)}
        />
      ) : null}

      {/* D-176/AC-8.3: replaces the reserved-playback placeholder, directly beneath the tracklist. */}
      <PlaybackPanel testID="playback-panel" playlist={playlist} providers={providersQuery.data} />
    </View>
  );
}
