import React, { useState } from "react";
import { useLocalSearchParams, useRouter } from "expo-router";
import { ScrollView, View } from "react-native";

import { EmptyState, LoadingState } from "@/components/state";
import {
  GenerationProgress,
  PlaylistDegradedState,
  ResultCard,
} from "@/components/playlist";
import {
  useConcertPlaylistJobs,
  useCreateAnyway,
  usePlaylistDetail,
  usePlaylistJobPolling,
  useProviderConfigs,
  useRetryGeneration,
  useStartGeneration,
  derivePlaylistView,
  pickCurrentJob,
} from "@/lib/playlist";
// Relative, not `@/` — the eslint import resolver only follows the platform-suffix convention
// through a relative specifier (see `frontend/README.md`, and `ConnectionsSection.tsx`).
import { linkAccount } from "../../../../lib/streaming/linkAccount";
import { useResolveStreamingLink, useStartStreamingLink, useStreamingAccounts } from "@/lib/streaming";
import { useTheme } from "@/theme";

/**
 * `Progress.dc.html` + the four `Result*`/six `Degraded*`/two failure artboards, all behind ONE
 * route (D-162) — `derivePlaylistView()` decides which one renders. Navigating away issues no
 * cancel (AC-3.1); this screen only starts/stops polling.
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
