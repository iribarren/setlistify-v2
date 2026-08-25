import React, { useState } from "react";
import { useLocalSearchParams, useRouter } from "expo-router";
import { ScrollView, Text, View } from "react-native";

import { EmptyState, LoadingState } from "@/components/state";
import {
  ConfirmSummary,
  GenerationProgress,
  PlaylistDegradedState,
  ResultCard,
  SetlistPicker,
  VersionPicker,
  defaultCandidateFor,
} from "@/components/playlist";
import { Button } from "@/components";
import { ApiError } from "@/lib/api";
import {
  useCandidateSetlists,
  useConcertPlaylistJobs,
  useCreateAnyway,
  usePendingChoices,
  usePlaylistChoiceDraft,
  usePlaylistDetail,
  usePlaylistJobPolling,
  useProviderConfigs,
  useRetryGeneration,
  useStartGeneration,
  useSubmitSetlistChoice,
  useSubmitVersionChoices,
  derivePlaylistView,
  pickCurrentJob,
} from "@/lib/playlist";
import type { SetlistChoiceItemInput, VersionChoiceItemInput } from "@/lib/playlist";
// Relative, not `@/` — the eslint import resolver only follows the platform-suffix convention
// through a relative specifier (see `frontend/README.md`, and `ConnectionsSection.tsx`).
import { linkAccount } from "../../../../lib/streaming/linkAccount";
import { useResolveStreamingLink, useStartStreamingLink, useStreamingAccounts } from "@/lib/streaming";
import { useTheme } from "@/theme";

/**
 * `Progress.dc.html` + the four `Result*`/six `Degraded*`/two failure artboards + Normal mode's
 * `SetlistSelect`/`VersionSelect`/`Confirm`/expiry artboards, all behind ONE route (D-162/D-202) —
 * `derivePlaylistView()` decides which one renders. Navigating away issues no cancel (AC-3.1); this
 * screen only starts/stops polling.
 */
export default function PlaylistScreen(): React.JSX.Element {
  const theme = useTheme();
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();
  const concertId = String(id);

  const jobsQuery = useConcertPlaylistJobs(concertId);
  const providersQuery = useProviderConfigs();
  const accountsQuery = useStreamingAccounts();
  const startGeneration = useStartGeneration();

  // AC-3.2: resolve the current job for this concert once, then hand its id to the poller.
  const resolvedJobId = pickCurrentJob(jobsQuery.data ?? [])?.id;
  const jobId = resolvedJobId ? String(resolvedJobId) : null;

  const polling = usePlaylistJobPolling(jobId);
  const job = polling.data ?? pickCurrentJob(jobsQuery.data ?? []);

  const playlistId = job?.playlistId ? String(job.playlistId) : null;
  const playlistDetail = usePlaylistDetail(playlistId);

  const retryGeneration = useRetryGeneration(jobId ?? "");
  const createAnyway = useCreateAnyway(jobId ?? "");
  const startStreamingLink = useStartStreamingLink();
  const resolveStreamingLink = useResolveStreamingLink();
  const [reconnecting, setReconnecting] = useState(false);

  const view = derivePlaylistView(job ?? null, playlistDetail.data ?? null, providersQuery.data, accountsQuery.data);

  // --- Normal mode (docs/specs/2026-08-25-playlist-normal-mode.md, D-190/D-202) -------------------
  const candidateSetlists = useCandidateSetlists(view.kind === "choose_setlist" ? jobId : null);
  const submitSetlistChoice = useSubmitSetlistChoice();
  const pendingChoices = usePendingChoices(view.kind === "choose_versions" ? jobId : null);
  const submitVersionChoices = useSubmitVersionChoices();
  const draft = usePlaylistChoiceDraft(jobId);
  // D-194: the confirm summary is a CLIENT-SIDE sub-step within `choose_versions` — no server state.
  const [versionStep, setVersionStep] = useState<"review" | "confirm">("review");
  // A fresh job (or leaving choose_versions) always starts back at the review sub-step. Adjusted
  // during render (React's documented pattern for resetting state when a prop changes) rather than
  // in an effect, since an effect would fire a synchronous setState and cascade an extra render.
  const [reviewResetKey, setReviewResetKey] = useState({ jobId, kind: view.kind });
  if (reviewResetKey.jobId !== jobId || reviewResetKey.kind !== view.kind) {
    setReviewResetKey({ jobId, kind: view.kind });
    setVersionStep("review");
  }

  async function handleSubmitSetlistChoice(selections: SetlistChoiceItemInput[]): Promise<void> {
    if (!jobId) {
      return;
    }
    try {
      await submitSetlistChoice.mutateAsync({ jobId, input: { choices: selections } });
    } catch (error) {
      // AC-6.5: a 422 (wrong state) means refetch and re-render from server truth, never a patch-over.
      if (error instanceof ApiError && error.status === 422) {
        await polling.refetch();
      } else {
        throw error;
      }
    }
  }

  async function handleBuildPlaylist(): Promise<void> {
    if (!jobId || !pendingChoices.data) {
      return;
    }
    const choices: VersionChoiceItemInput[] = (pendingChoices.data.decisions ?? [])
      .filter((decision): decision is typeof decision & { sourcePosition: number } => decision.sourcePosition != null)
      .map((decision) => {
        const chosen =
          decision.sourcePosition in draft.draft.versionChoices
            ? draft.draft.versionChoices[decision.sourcePosition]
            : (defaultCandidateFor(decision)?.providerTrackId ?? null);
        return {
          sourcePosition: decision.sourcePosition,
          segmentIndex: decision.segmentIndex ?? null,
          providerTrackId: chosen,
        };
      });
    try {
      await submitVersionChoices.mutateAsync({ jobId, input: { choices } });
      draft.clear();
      setVersionStep("review");
    } catch (error) {
      if (error instanceof ApiError && error.status === 422) {
        await polling.refetch();
      } else {
        throw error;
      }
    }
  }

  async function handleStartFresh(): Promise<void> {
    if (!job?.id) {
      return;
    }
    await startGeneration.mutateAsync({
      concertId,
      provider: job.provider,
      mode: "normal",
      resumeFromJobId: String(job.id),
    });
    await jobsQuery.refetch();
  }

  // AC-6.4: on a successful reconnect, refetch the job so the UI shows it resuming (F-06 re-queues
  // it server-side once the account returns to `connected`).
  async function handleReconnect(): Promise<void> {
    if (!job?.provider) {
      return;
    }
    setReconnecting(true);
    try {
      const authorizationUrl = await startStreamingLink.mutateAsync(job.provider);
      const result = await linkAccount(authorizationUrl);
      if (result.ref) {
        await resolveStreamingLink.mutateAsync(result.ref);
      }
      if (jobId) {
        await polling.refetch();
      }
    } finally {
      setReconnecting(false);
    }
  }

  if (jobsQuery.isLoading) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <LoadingState testID="playlist-loading" title="Loading…" />
      </View>
    );
  }

  if (view.kind === "idle") {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <EmptyState
          testID="playlist-idle"
          title="No generation in progress."
          body="Start a new one from the concert page."
          action={{ label: "Back to concert", onPress: () => router.replace(`/concerts/${concertId}`) }}
        />
      </View>
    );
  }

  return (
    <ScrollView
      testID="playlist-screen"
      contentContainerStyle={{ padding: theme.space("space-6") }}
      style={{ backgroundColor: theme.colors["bg"] }}
    >
      <View style={{ width: "100%", maxWidth: 640, alignSelf: "center" }}>
        {view.kind === "progress" && job ? <GenerationProgress testID="playlist-progress" job={job} /> : null}

        {view.kind === "choose_setlist" && job ? (
          candidateSetlists.isLoading || !candidateSetlists.data ? (
            <LoadingState testID="playlist-choose-setlist-loading" title="Loading the nights we found…" />
          ) : (
            <SetlistPicker
              testID="playlist-choose-setlist"
              candidateSetlists={candidateSetlists.data}
              choices={draft.draft.setlistChoices}
              onChoose={draft.setSetlistChoice}
              onSubmit={(selections) => void handleSubmitSetlistChoice(selections)}
              submitting={submitSetlistChoice.isPending}
            />
          )
        ) : null}

        {view.kind === "choose_versions" && job ? (
          pendingChoices.isLoading || !pendingChoices.data ? (
            <LoadingState testID="playlist-choose-versions-loading" title="Looking at what needs a look…" />
          ) : (pendingChoices.data.decisions ?? []).length === 0 ? (
            // AC-2.7/D-195: an empty CHOICE band is deliberately NOT a screen — same as Fast mode.
            <GenerationProgress testID="playlist-progress" job={job} />
          ) : versionStep === "review" ? (
            <VersionPicker
              testID="playlist-choose-versions"
              pendingChoices={pendingChoices.data}
              choices={draft.draft.versionChoices}
              onChoose={draft.setVersionChoice}
              onContinue={() => setVersionStep("confirm")}
            />
          ) : (
            <ConfirmSummary
              testID="playlist-confirm"
              pendingChoices={pendingChoices.data}
              choices={draft.draft.versionChoices}
              onBack={() => setVersionStep("review")}
              onBuild={() => void handleBuildPlaylist()}
              building={submitVersionChoices.isPending}
            />
          )
        ) : null}

        {view.kind === "expired" ? (
          <View testID="playlist-expired" style={{ alignItems: "center", gap: theme.space("space-3"), padding: theme.space("space-6") }}>
            <Text
              style={{
                color: theme.colors["text-primary"],
                fontFamily: theme.resolveFontFamily("display", "semibold"),
                fontSize: theme.typeScale.lg.fontSize,
                textAlign: "center",
              }}
            >
              This paused session lapsed
            </Text>
            <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.sm.fontSize, textAlign: "center", maxWidth: 380 }}>
              The candidates it held aren&apos;t current any more. Starting fresh keeps every choice you
              already made — anything that&apos;s no longer available will ask again.
            </Text>
            <Button
              testID="playlist-expired-start-fresh"
              label={startGeneration.isPending ? "Starting…" : "Start fresh"}
              onPress={() => void handleStartFresh()}
              disabled={startGeneration.isPending}
            />
          </View>
        ) : null}

        {(view.kind === "result_full" ||
          view.kind === "result_mostly" ||
          view.kind === "result_barely" ||
          view.kind === "result_nothing") &&
        job ? (
          <ResultCard
            testID="playlist-result"
            kind={view.kind}
            job={job}
            playlist={playlistDetail.data ?? null}
            providerDisplayName={
              providersQuery.data?.find((provider) => provider.key === job.provider)?.displayName ?? "your provider"
            }
            onSeeReport={() => router.push(`/concerts/${concertId}/playlist-report`)}
          />
        ) : null}

        {(view.kind === "degraded_band_unknown" ||
          view.kind === "degraded_no_songs" ||
          view.kind === "blocked_budget" ||
          view.kind === "blocked_quota" ||
          view.kind === "blocked_reauth" ||
          view.kind === "blocked_disabled" ||
          view.kind === "blocked_upstream" ||
          view.kind === "failed_indeterminate" ||
          view.kind === "failed_generic") &&
        job ? (
          <PlaylistDegradedState
            testID={`playlist-${view.kind}`}
            kind={view.kind}
            job={job}
            providerDisplayName={
              providersQuery.data?.find((provider) => provider.key === job.provider)?.displayName ?? "your provider"
            }
            alternativeProvider={view.alternativeProvider}
            onReconnect={() => void handleReconnect()}
            onUseAlternative={(providerKey) => void startGeneration.mutateAsync({ concertId, provider: providerKey })}
            onRetry={() => void retryGeneration.mutateAsync()}
            onCreateAnyway={() => void createAnyway.mutateAsync()}
            onCheckAgain={() => void jobsQuery.refetch()}
          />
        ) : null}

        {reconnecting ? <LoadingState testID="playlist-reconnecting" title="Reconnecting…" /> : null}
      </View>
    </ScrollView>
  );
}
