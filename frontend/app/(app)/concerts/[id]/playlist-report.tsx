import React from "react";
import { useLocalSearchParams, useRouter } from "expo-router";
import { ScrollView, Text, View } from "react-native";

import { Badge, Button } from "@/components";
import { ReportList } from "@/components/playlist";
import { EmptyState, ErrorState, LoadingState } from "@/components/state";
import { useConcertPlaylists } from "@/lib/playlist";
import { useTheme } from "@/theme";

/**
 * `Report.dc.html`. AC-5.1: only unmatched/needs-attention rows — matched songs never appear.
 * D-171: read-only in Fast mode (no row actions — those are prompt 17's `VersionSelect`).
 */
export default function PlaylistReportScreen(): React.JSX.Element {
  const theme = useTheme();
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();
  const concertId = String(id);

  const playlistsQuery = useConcertPlaylists(concertId);
  const playlist = (playlistsQuery.data ?? [])[0] ?? null;

  if (playlistsQuery.isLoading) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <LoadingState testID="playlist-report-loading" title="Loading report…" />
      </View>
    );
  }

  if (playlistsQuery.isError) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <ErrorState
          testID="playlist-report-error"
          title="Couldn't load the report."
          body="Please try again."
          action={{ label: "Try again", onPress: () => void playlistsQuery.refetch() }}
        />
      </View>
    );
  }

  if (!playlist) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <EmptyState testID="playlist-report-empty" title="No report yet." body="Generate a playlist first." />
      </View>
    );
  }

  const matchRate = playlist.matchRate ?? 0;

  return (
    <ScrollView
      testID="playlist-report-screen"
      contentContainerStyle={{ padding: theme.space("space-6") }}
      style={{ backgroundColor: theme.colors["bg"] }}
    >
      <View style={{ width: "100%", maxWidth: 640, alignSelf: "center", gap: theme.space("space-4") }}>
        <Badge testID="playlist-report-rate" label={`${Math.round(matchRate * 100)}% matched`} variant="info" />
        <Text
          style={{
            color: theme.colors["text-primary"],
            fontFamily: theme.resolveFontFamily("display", "semibold"),
            fontSize: theme.typeScale.lg.fontSize,
          }}
        >
          Songs that need a look
        </Text>
        <ReportList testID="playlist-report-list" summary={playlist.report ?? []} tracks={playlist.tracks ?? []} />
        <Button testID="playlist-report-back" label="Back to concert" variant="secondary" onPress={() => router.replace(`/concerts/${concertId}`)} />
      </View>
    </ScrollView>
  );
}
