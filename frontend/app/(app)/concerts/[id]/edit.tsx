import React, { useState } from "react";
import { useLocalSearchParams, useRouter } from "expo-router";
import { ScrollView, Text, View } from "react-native";

import { Button, Card } from "@/components";
import { ConcertForm, DeleteConfirmation } from "@/components/concert";
import { EmptyState, ErrorState, LoadingState } from "@/components/state";
import {
  concertOutputToFormValues,
  describeConcertError,
  isNotFoundError,
  mapViolationsToFields,
  useConcert,
  useDeleteConcert,
  useUpdateConcert,
  violationsFromError,
  type ConcertFormValues,
  type ViolationFieldErrors,
} from "@/lib/concerts";
import { useTheme } from "@/theme";

/**
 * `EditDelete.dc.html` (US-6/US-7). AC-6.1: reuses `ConcertForm`, pre-filled from the concert.
 * AC-6.2: `PATCH` as JSON merge-patch via `useUpdateConcert`. Delete lives here too, alongside the
 * detail screen (AC-7.1).
 */
export default function EditConcertScreen(): React.JSX.Element {
  const theme = useTheme();
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();
  const concertId = String(id);
  const { data: concert, isLoading, isError, error, refetch } = useConcert(concertId);
  const update = useUpdateConcert(concertId);
  const deleteConcert = useDeleteConcert(concertId);

  const [formError, setFormError] = useState<string | null>(null);
  const [violations, setViolations] = useState<ViolationFieldErrors | null>(null);
  const [dirty, setDirty] = useState(false);
  const [confirmingDiscard, setConfirmingDiscard] = useState(false);
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  if (isLoading) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <LoadingState testID="edit-concert-loading" title="Loading concert…" />
      </View>
    );
  }

  if (isError && isNotFoundError(error)) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.colors["bg"], justifyContent: "center" }}>
        <EmptyState
          testID="edit-concert-not-found"
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
          testID="edit-concert-error"
          title="Couldn't load this concert."
          body={describeConcertError(error)}
          action={{ label: "Try again", onPress: () => void refetch() }}
        />
      </View>
    );
  }

  const isPast = concert.status === "past";

  function goBack(): void {
    router.back();
  }

  function handleCancel(): void {
    // AC-6.6: leaving the form with unsaved changes asks for confirmation.
    if (dirty) {
      setConfirmingDiscard(true);
      return;
    }
    goBack();
  }

  async function handleSubmit(values: ConcertFormValues): Promise<void> {
    setFormError(null);
    setViolations(null);
    try {
      await update.mutateAsync(values);
      router.back();
    } catch (submitError) {
      const rawViolations = violationsFromError(submitError);
      if (rawViolations) {
        setViolations(mapViolationsToFields(rawViolations));
      } else {
        setFormError(describeConcertError(submitError));
      }
    }
  }

  async function handleDelete(): Promise<void> {
    setDeleteError(null);
    try {
      await deleteConcert.mutateAsync();
      router.replace("/concerts");
    } catch (deleteFailure) {
      setDeleteError(describeConcertError(deleteFailure));
    }
  }

  return (
    <ScrollView
      testID="edit-concert-screen"
      contentContainerStyle={{ padding: theme.space("space-6") }}
      style={{ backgroundColor: theme.colors["bg"] }}
    >
      <View style={{ width: "100%", maxWidth: 560, alignSelf: "center", gap: theme.space("space-6") }}>
        <Text
          style={{
            color: theme.colors["text-primary"],
            fontFamily: theme.resolveFontFamily("display", "semibold"),
            fontSize: theme.typeScale["2xl"].fontSize,
            lineHeight: theme.typeScale["2xl"].lineHeight,
          }}
        >
          Edit concert
        </Text>

        {confirmingDiscard ? (
          <DiscardChangesPrompt
            onKeepEditing={() => setConfirmingDiscard(false)}
            onDiscard={() => {
              setConfirmingDiscard(false);
              goBack();
            }}
          />
        ) : (
          <ConcertForm
            initialValues={concertOutputToFormValues(concert)}
            onSubmit={handleSubmit}
            submitLabel="Save changes"
            submitting={update.isPending}
            serverViolations={violations}
            formError={formError}
            dateLocked={isPast}
            onCancel={handleCancel}
            onDirtyChange={setDirty}
          />
        )}

        {confirmingDelete ? (
          <DeleteConfirmation
            testID="delete-confirmation"
            concertLabel="This concert"
            deleting={deleteConcert.isPending}
            onConfirm={() => void handleDelete()}
            onCancel={() => setConfirmingDelete(false)}
          />
        ) : (
          <Button
            testID="delete-concert-button"
            label="Delete concert"
            variant="destructive"
            onPress={() => setConfirmingDelete(true)}
          />
        )}

        {deleteError ? (
          <ErrorState
            testID="delete-error"
            title="Couldn't delete this concert."
            body={deleteError}
            action={{ label: "Try again", onPress: () => setConfirmingDelete(true) }}
          />
        ) : null}
      </View>
    </ScrollView>
  );
}

function DiscardChangesPrompt({
  onKeepEditing,
  onDiscard,
}: {
  onKeepEditing: () => void;
  onDiscard: () => void;
}): React.JSX.Element {
  const theme = useTheme();

  return (
    <Card testID="discard-changes-confirmation">
      <View style={{ gap: theme.space("space-4") }}>
        <Text
          style={{
            color: theme.colors["text-primary"],
            fontFamily: theme.resolveFontFamily("display", "semibold"),
            fontSize: theme.typeScale.lg.fontSize,
          }}
        >
          Discard unsaved changes?
        </Text>
        <View style={{ flexDirection: "row", gap: theme.space("space-3"), justifyContent: "flex-end" }}>
          <Button testID="keep-editing" label="Keep editing" variant="secondary" onPress={onKeepEditing} />
          <Button testID="discard-changes" label="Discard" variant="destructive" onPress={onDiscard} />
        </View>
      </View>
    </Card>
  );
}
