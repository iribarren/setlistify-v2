import React, { useState } from "react";
import { useLocalSearchParams, useRouter } from "expo-router";
import { ScrollView, Text, View } from "react-native";

import { Badge, Button, Card } from "@/components";
import { DeleteConfirmation, LineupList, ReservedSection } from "@/components/concert";
import { PlaylistSection } from "@/components/playlist";
import { ReviewSection } from "@/components/review";
import { EmptyState, ErrorState, LoadingState } from "@/components/state";
import {
  describeConcertError,
  formatConcertDate,
  formatMoney,
  isNotFoundError,
  useConcert,
  useDeleteConcert,
} from "@/lib/concerts";
import { useTheme } from "@/theme";

/**
 * `ConcertDetail.dc.html` (US-5). AC-5.6/US-11: a 404 renders the ordinary not-found state — the
 * exact same output for a deleted id, an unknown id and another user's id (D-27).
 */
export default function ConcertDetailScreen(): React.JSX.Element {
  const theme = useTheme();
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();
  const concertId = String(id);
  const { data: concert, isLoading, isError, error, refetch } = useConcert(concertId);
  const deleteConcert = useDeleteConcert(concertId);
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  if (isLoading) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <LoadingState testID="concert-detail-loading" title="Loading concert…" />
      </View>
    );
  }

  if (isError && isNotFoundError(error)) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <EmptyState
          testID="concert-detail-not-found"
          title="This concert couldn't be found."
          body="It may have been deleted, or the link may be wrong."
          action={{ label: "Back to concerts", onPress: () => router.replace("/concerts") }}
        />
      </View>
    );
  }

  if (isError || !concert) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <ErrorState
          testID="concert-detail-error"
          title="Couldn't load this concert."
          body={describeConcertError(error)}
          action={{ label: "Try again", onPress: () => void refetch() }}
        />
      </View>
    );
  }

  const isPast = concert.status === "past";
  const venue = [concert.venue?.name, concert.venue?.city].filter(Boolean).join(", ");
  const money = formatMoney(concert.ticketPrice);
  const headliner = [...(concert.lineup ?? [])].sort((a, b) => (a.billingOrder ?? 0) - (b.billingOrder ?? 0))[0]
    ?.band?.name;
  const concertLabel = [headliner, venue].filter(Boolean).join(" — ");

  async function handleDelete(): Promise<void> {
    setDeleteError(null);
    try {
      await deleteConcert.mutateAsync();
      router.replace("/concerts");
    } catch (deleteFailure) {
      // AC-7.4: a failed delete leaves the concert visibly present and explains the failure.
      setDeleteError(describeConcertError(deleteFailure));
    }
  }

  return (
    <ScrollView
      testID="concert-detail"
      contentContainerStyle={{ padding: theme.space("space-6"), gap: theme.space("space-5") }}
      style={{ backgroundColor: theme.colors["bg"] }}
    >
      <View style={{ width: "100%", maxWidth: 640, alignSelf: "center", gap: theme.space("space-5") }}>
        <View style={{ flexDirection: "row", alignItems: "center", justifyContent: "space-between" }}>
          <Badge label={isPast ? "Past" : "Upcoming"} variant={isPast ? "neutral" : "info"} />
          <View style={{ flexDirection: "row", gap: theme.space("space-2") }}>
            <Button
              testID="edit-concert-button"
              label="Edit"
              variant="secondary"
              onPress={() => router.push(`/concerts/${concertId}/edit`)}
            />
            <Button
              testID="delete-concert-button"
              label="Delete"
              variant="destructive"
              onPress={() => setConfirmingDelete(true)}
            />
          </View>
        </View>

        <Card>
          <View style={{ gap: theme.space("space-4") }}>
            {concert.date && concert.timezone ? (
              <Text
                style={{
                  color: theme.colors["text-tertiary"],
                  fontFamily: theme.resolveFontFamily("mono", "medium"),
                  fontSize: theme.typeScale.sm.fontSize,
                }}
              >
                {formatConcertDate(concert.date, concert.timezone)}
              </Text>
            ) : null}

            <View style={{ gap: theme.space("space-2") }}>
              <Text
                style={{
                  color: theme.colors["text-primary"],
                  fontFamily: theme.resolveFontFamily("display", "semibold"),
                  fontSize: theme.typeScale.sm.fontSize,
                }}
              >
                Lineup
              </Text>
              <LineupList testID="concert-detail-lineup" lineup={concert.lineup ?? []} />
            </View>

            {venue ? (
              <View style={{ gap: theme.space("space-1") }}>
                <Text style={{ color: theme.colors["text-tertiary"], fontFamily: theme.resolveFontFamily("body", "regular"), fontSize: theme.typeScale.sm.fontSize }}>
                  Venue
                </Text>
                <Text style={{ color: theme.colors["text-primary"], fontFamily: theme.resolveFontFamily("body", "medium"), fontSize: theme.typeScale.base.fontSize }}>
                  {venue}
                </Text>
              </View>
            ) : null}

            {money ? (
              <View style={{ gap: theme.space("space-1") }}>
                <Text style={{ color: theme.colors["text-tertiary"], fontFamily: theme.resolveFontFamily("body", "regular"), fontSize: theme.typeScale.sm.fontSize }}>
                  Ticket price
                </Text>
                <Text style={{ color: theme.colors["text-primary"], fontFamily: theme.resolveFontFamily("body", "medium"), fontSize: theme.typeScale.base.fontSize }}>
                  {money}
                </Text>
              </View>
            ) : null}

            {concert.doorsTime || concert.startTime ? (
              <Text style={{ color: theme.colors["text-secondary"], fontFamily: theme.resolveFontFamily("body", "regular"), fontSize: theme.typeScale.sm.fontSize }}>
                {[concert.doorsTime && `Doors ${concert.doorsTime}`, concert.startTime && `Show ${concert.startTime}`]
                  .filter(Boolean)
                  .join(" · ")}
              </Text>
            ) : null}
          </View>
        </Card>

        {confirmingDelete ? (
          <DeleteConfirmation
            testID="delete-confirmation"
            concertLabel={concertLabel || "This concert"}
            deleting={deleteConcert.isPending}
            onConfirm={() => void handleDelete()}
            onCancel={() => setConfirmingDelete(false)}
          />
        ) : null}

        {deleteError ? (
          <ErrorState
            testID="delete-error"
            title="Couldn't delete this concert."
            body={deleteError}
            action={{ label: "Try again", onPress: () => setConfirmingDelete(true) }}
          />
        ) : null}

        {/* D-176: the reserved-playlist placeholder is now the real Playlist section — its own
            reserved-playback placeholder (for prompt 19) lives inside it, once a playlist exists. */}
        <PlaylistSection testID="playlist-section" concertId={concertId} />
        <ReviewSection testID="review-section" concert={concert} />
        <ReservedSection testID="reserved-share" title="Share" comingIn="prompt 21" />
      </View>
    </ScrollView>
  );
}
