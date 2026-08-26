import React, { useState } from "react";
import { useRouter } from "expo-router";
import { RefreshControl, ScrollView, Text, View } from "react-native";

import { Button } from "@/components";
import { ConcertCard, SkeletonCard } from "@/components/concert";
import { ReviewPromptCard } from "@/components/review";
import { EmptyState, ErrorState } from "@/components/state";
import { useConcertsSection, type CachedConcert, type ConcertSectionStatus } from "@/lib/concerts";
import { useReviewPromptCard } from "@/lib/review";
import { useTheme } from "@/theme";

/**
 * `Main.dc.html` (US-1) + `EmptyState.dc.html` (US-2) + `States.dc.html` (loading/error). D-32: one
 * scroll with Upcoming/Past sections backed by two independent `useConcertsSection` queries, so the
 * layout can change to tabs later without touching the data layer.
 */
export default function ConcertsListScreen(): React.JSX.Element {
  const theme = useTheme();
  const router = useRouter();
  const upcoming = useConcertsSection("upcoming");
  const past = useConcertsSection("past");
  const [refreshing, setRefreshing] = useState(false);

  const upcomingItems = upcoming.data?.pages.flatMap((page) => page.member) ?? [];
  const pastItems = past.data?.pages.flatMap((page) => page.member) ?? [];

  // AC-1.4: skeletons while the FIRST page loads — not on every background refetch.
  const initialLoading = (upcoming.isLoading && !upcoming.data) || (past.isLoading && !past.data);

  // AC-7.1-AC-7.3: called unconditionally every render (rules of hooks) — it defers its one-shot
  // pick internally until the past section's first page has actually loaded.
  const reviewPrompt = useReviewPromptCard(pastItems, Boolean(past.data));

  const hasAnyCachedData = Boolean(upcoming.data) || Boolean(past.data);
  // AC-1.8: a list-level failure only replaces the whole area when there's nothing cached to fall
  // back to (D-37) — an offline refetch failure with data already on screen just keeps showing it.
  const showFullError = (upcoming.isError || past.isError) && !hasAnyCachedData;

  // AC-2.1: empty across BOTH statuses, not loading, not errored.
  const isEmpty = !initialLoading && !showFullError && upcomingItems.length === 0 && pastItems.length === 0;

  async function handleRefresh(): Promise<void> {
    setRefreshing(true);
    try {
      await Promise.all([upcoming.refetch(), past.refetch()]);
    } finally {
      setRefreshing(false);
    }
  }

  function goToAdd(): void {
    router.push("/concerts/new");
  }

  function goToDetail(concert: CachedConcert): void {
    if (concert.id == null) {
      return; // AC-4.5: an optimistic/pending card has no server id yet — not tappable through.
    }
    router.push(`/concerts/${concert.id}`);
  }

  if (initialLoading) {
    return (
      <ScrollView
        testID="concerts-loading"
        contentContainerStyle={{ padding: theme.space("space-6"), gap: theme.space("space-4") }}
        style={{ backgroundColor: theme.colors["bg"] }}
      >
        <ScreenHeader />
        <SkeletonCard />
        <SkeletonCard />
        <SkeletonCard />
      </ScrollView>
    );
  }

  if (showFullError) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <ErrorState
          testID="concerts-error"
          title="Couldn't load your concerts."
          body="Check your connection and try again."
          action={{
            label: "Try again",
            onPress: () => {
              void upcoming.refetch();
              void past.refetch();
            },
          }}
        />
      </View>
    );
  }

  if (isEmpty) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <EmptyState
          testID="concerts-empty"
          title="No concerts yet"
          body="Track a concert you're going to (or already went to) — bands, date, venue. Takes about 15 seconds."
          action={{ label: "Add concert", onPress: goToAdd }}
        />
      </View>
    );
  }

  return (
    <ScrollView
      testID="concerts-list"
      contentContainerStyle={{ padding: theme.space("space-6"), gap: theme.space("space-6") }}
      style={{ backgroundColor: theme.colors["bg"] }}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void handleRefresh()} />}
    >
      <ScreenHeader onAdd={goToAdd} count={{ upcoming: upcomingItems.length, past: pastItems.length }} />

      <Section
        testID="upcoming-section"
        title="Upcoming"
        status="upcoming"
        items={upcomingItems}
        emptyText="No upcoming concerts yet"
        hasNextPage={Boolean(upcoming.hasNextPage)}
        isFetchingNextPage={upcoming.isFetchingNextPage}
        onLoadMore={() => void upcoming.fetchNextPage()}
        onPressItem={goToDetail}
      />

      {reviewPrompt.concert ? (
        <ReviewPromptCard
          testID="review-prompt-card"
          concert={reviewPrompt.concert}
          onPress={() => goToDetail(reviewPrompt.concert as CachedConcert)}
          onDismiss={reviewPrompt.dismiss}
        />
      ) : null}

      <Section
        testID="past-section"
        title="Past"
        status="past"
        items={pastItems}
        emptyText="No past concerts yet"
        hasNextPage={Boolean(past.hasNextPage)}
        isFetchingNextPage={past.isFetchingNextPage}
        onLoadMore={() => void past.fetchNextPage()}
        onPressItem={goToDetail}
      />
    </ScrollView>
  );
}

function ScreenHeader({
  onAdd,
  count,
}: {
  onAdd?: () => void;
  count?: { upcoming: number; past: number };
}): React.JSX.Element {
  const theme = useTheme();
  return (
    <View style={{ gap: theme.space("space-2") }}>
      <View style={{ flexDirection: "row", alignItems: "center", justifyContent: "space-between" }}>
        <Text
          style={{
            color: theme.colors["text-primary"],
            fontFamily: theme.resolveFontFamily("display", "semibold"),
            fontSize: theme.typeScale["2xl"].fontSize,
            lineHeight: theme.typeScale["2xl"].lineHeight,
          }}
        >
          Your concerts
        </Text>
        {onAdd ? <Button testID="add-concert-button" label="Add concert" onPress={onAdd} /> : null}
      </View>
      {count ? (
        <Text
          style={{
            color: theme.colors["text-tertiary"],
            fontFamily: theme.resolveFontFamily("body", "regular"),
            fontSize: theme.typeScale.sm.fontSize,
          }}
        >
          {count.upcoming} upcoming · {count.past} past
        </Text>
      ) : null}
    </View>
  );
}

function Section({
  testID,
  title,
  items,
  emptyText,
  hasNextPage,
  isFetchingNextPage,
  onLoadMore,
  onPressItem,
}: {
  testID: string;
  title: string;
  status: ConcertSectionStatus;
  items: CachedConcert[];
  emptyText: string;
  hasNextPage: boolean;
  isFetchingNextPage: boolean;
  onLoadMore: () => void;
  onPressItem: (concert: CachedConcert) => void;
}): React.JSX.Element {
  const theme = useTheme();

  return (
    <View testID={testID} style={{ gap: theme.space("space-3") }}>
      <Text
        style={{
          color: theme.colors["text-primary"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize: theme.typeScale.lg.fontSize,
          lineHeight: theme.typeScale.lg.lineHeight,
        }}
      >
        {title}
      </Text>
      {items.length === 0 ? (
        <Text
          testID={`${testID}-empty`}
          style={{
            color: theme.colors["text-tertiary"],
            fontFamily: theme.resolveFontFamily("body", "regular"),
            fontSize: theme.typeScale.sm.fontSize,
          }}
        >
          {emptyText}
        </Text>
      ) : (
        <View style={{ gap: theme.space("space-3") }}>
          {items.map((concert) => (
            <ConcertCard
              key={concert.__tempId ?? concert.id}
              testID={`concert-card-${concert.__tempId ?? concert.id}`}
              concert={concert}
              onPress={() => onPressItem(concert)}
            />
          ))}
        </View>
      )}
      {hasNextPage ? (
        <Button
          testID={`${testID}-load-more`}
          label="Load more"
          variant="secondary"
          disabled={isFetchingNextPage}
          onPress={onLoadMore}
        />
      ) : null}
    </View>
  );
}
