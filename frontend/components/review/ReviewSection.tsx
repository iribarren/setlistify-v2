import React, { useState } from "react";
import { Text, View } from "react-native";

import { Button, Card } from "@/components";
// Concrete file, not the `@/components/concert` barrel — see the note in ReviewEditor.tsx.
import { DeleteConfirmation } from "@/components/concert/DeleteConfirmation";
import { useConcertReview, useDeleteConcertReview, useSaveConcertReview } from "@/hooks/useConcertReview";
import type { ConcertOutput } from "@/lib/concerts";
import { describeConcertError } from "@/lib/concerts";
import { useHighlightSources, type ConcertReviewInput } from "@/lib/review";
import { useTheme } from "@/theme";

import { ReviewEditor, type ReviewEditorInitialValue } from "./ReviewEditor";
import { StarRating } from "./StarRating";

export interface ReviewSectionProps {
  concert: ConcertOutput;
  testID?: string;
}

const EMPTY_DRAFT: ReviewEditorInitialValue = { rating: null, notes: "", highlightSongId: null, highlightTitle: "" };

/**
 * `ReviewSection` — US-1, US-2, US-7 (D-234/D-235). Replaces the `reserved-note` placeholder
 * (D-176-style slot reuse). AC-1.1: sits directly below `PlaylistSection`. Three states: an
 * upcoming concert renders a de-emphasized, compose-free panel (matching the canvas's upcoming
 * playlist-region treatment); a past concert with no review shows ONE affordance and nothing else
 * (AC-1.2 — no empty stars, no empty box); a past concert with a review shows it, plus edit/delete.
 */
export function ReviewSection({ concert, testID }: ReviewSectionProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const concertId = String(concert.id);
  const isUpcoming = concert.status !== "past";

  const reviewQuery = useConcertReview(concertId);
  const saveReview = useSaveConcertReview(concertId);
  const deleteReview = useDeleteConcertReview(concertId);
  // Only fetched for a past concert — an upcoming one can't have a review to attach a highlight to,
  // so there's no reason to spend the two round trips this hook makes per band.
  const highlightSources = useHighlightSources(isUpcoming ? undefined : concert);

  const [editing, setEditing] = useState(false);
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  // D-234: blocked server-side for a first write on an upcoming concert; de-emphasized here too,
  // with no compose affordance at all (AC-2.5's exemptions — edit/delete — never apply, since an
  // upcoming concert can't have an existing review in the first place, migration aside).
  if (isUpcoming) {
    return (
      <View
        testID={testID}
        style={{
          borderRadius: theme.rad("lg"),
          borderWidth: 1,
          borderStyle: "dashed",
          borderColor: colors["border-subtle"],
          padding: theme.space("space-5"),
          gap: theme.space("space-1"),
        }}
      >
        <Text
          style={{
            color: colors["text-secondary"],
            fontFamily: theme.resolveFontFamily("body", "semibold"),
            fontSize: theme.typeScale.sm.fontSize,
          }}
        >
          Your review
        </Text>
        <Text style={{ color: colors["text-tertiary"], fontFamily: theme.resolveFontFamily("body", "regular"), fontSize: theme.typeScale.xs.fontSize }}>
          Unlocks after the show.
        </Text>
      </View>
    );
  }

  async function handleSave(input: ConcertReviewInput): Promise<void> {
    setSaveError(null);
    try {
      await saveReview.mutateAsync(input);
      setEditing(false);
    } catch (error) {
      // AC-1.6/D-246: the editor stays open with the draft intact — nothing here closes it.
      setSaveError(describeConcertError(error));
    }
  }

  async function handleDelete(): Promise<void> {
    setDeleteError(null);
    try {
      await deleteReview.mutateAsync();
      setConfirmingDelete(false);
    } catch (error) {
      setDeleteError(describeConcertError(error));
    }
  }

  if (editing || (reviewQuery.isLoading && !reviewQuery.data)) {
    if (!editing) {
      return <View testID={testID ? `${testID}-loading` : undefined} />;
    }
    const review = reviewQuery.data;
    const initialValue: ReviewEditorInitialValue = review
      ? {
          rating: review.rating ?? null,
          notes: review.notes ?? "",
          highlightSongId: review.highlightSongId ?? null,
          highlightTitle: review.highlightTitle ?? "",
        }
      : EMPTY_DRAFT;

    return (
      <ReviewEditor
        testID={testID ? `${testID}-editor` : undefined}
        initialValue={initialValue}
        highlightGroups={highlightSources.groups}
        hasSetlist={highlightSources.hasSetlist}
        onSave={(input) => void handleSave(input)}
        onCancel={() => {
          setSaveError(null);
          setEditing(false);
        }}
        saving={saveReview.isPending}
        saveError={saveError}
      />
    );
  }

  const review = reviewQuery.data;

  if (!review) {
    // AC-1.2: an invitation, not a form — no empty stars, no empty text box.
    return (
      <View testID={testID} style={{ gap: theme.space("space-3") }}>
        <Text
          style={{
            color: colors["text-primary"],
            fontFamily: theme.resolveFontFamily("display", "semibold"),
            fontSize: theme.typeScale.base.fontSize,
          }}
        >
          Your review
        </Text>
        <Button testID={testID ? `${testID}-write` : undefined} label="Write about this show" onPress={() => setEditing(true)} />
      </View>
    );
  }

  // AC-2.1: rating, notes and the highlight, plus edit/delete.
  return (
    <View testID={testID} style={{ gap: theme.space("space-3") }}>
      <Text
        style={{
          color: colors["text-primary"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize: theme.typeScale.base.fontSize,
        }}
      >
        Your review
      </Text>
      <Card>
        <View style={{ gap: theme.space("space-3") }}>
          {review.rating != null ? (
            <StarRating testID={testID ? `${testID}-rating` : undefined} value={review.rating} />
          ) : null}
          {review.notes ? (
            <Text testID={testID ? `${testID}-notes` : undefined} style={{ color: colors["text-primary"], fontSize: theme.typeScale.base.fontSize, lineHeight: theme.typeScale.base.lineHeight }}>
              {review.notes}
            </Text>
          ) : null}
          {review.highlightTitle ? (
            <View style={{ gap: theme.space("space-1") }}>
              <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>Best song of the night</Text>
              <Text testID={testID ? `${testID}-highlight` : undefined} style={{ color: colors["text-primary"], fontFamily: theme.resolveFontFamily("body", "medium"), fontSize: theme.typeScale.sm.fontSize }}>
                {review.highlightTitle}
              </Text>
            </View>
          ) : null}
          <View style={{ flexDirection: "row", gap: theme.space("space-2") }}>
            <Button testID={testID ? `${testID}-edit` : undefined} label="Edit" variant="secondary" onPress={() => setEditing(true)} />
            <Button testID={testID ? `${testID}-delete` : undefined} label="Delete" variant="destructive" onPress={() => setConfirmingDelete(true)} />
          </View>
        </View>
      </Card>

      {confirmingDelete ? (
        <DeleteConfirmation
          testID={testID ? `${testID}-delete-confirmation` : undefined}
          concertLabel="This review"
          deleting={deleteReview.isPending}
          onConfirm={() => void handleDelete()}
          onCancel={() => setConfirmingDelete(false)}
        />
      ) : null}
      {deleteError ? (
        <Text accessibilityRole="alert" style={{ color: colors["error-strong"], fontSize: theme.typeScale.sm.fontSize }}>
          {deleteError}
        </Text>
      ) : null}
    </View>
  );
}
