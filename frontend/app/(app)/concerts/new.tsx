import React, { useMemo, useState } from "react";
import { useRouter } from "expo-router";
import { ScrollView, Text, View } from "react-native";

import { ConcertForm } from "@/components/concert";
import {
  createEmptyFormValues,
  describeConcertError,
  mapViolationsToFields,
  violationsFromError,
  type ConcertFormValues,
  type ViolationFieldErrors,
} from "@/lib/concerts";
import { useCreateConcert } from "@/lib/concerts";
import { useTheme } from "@/theme";

/**
 * `AddConcert.dc.html` (US-3). AC-4.1: the optimistic card is inserted by `useCreateConcert`'s
 * `onMutate`; this screen only needs to return to the list on submit and report a failure back
 * into the form with the user's input intact (AC-4.4) — the mutation's `onError` already rolled
 * the optimistic row back out of the cache.
 */
export default function NewConcertScreen(): React.JSX.Element {
  const theme = useTheme();
  const router = useRouter();
  const create = useCreateConcert();
  const initialValues = useMemo(() => createEmptyFormValues(), []);
  const [formError, setFormError] = useState<string | null>(null);
  const [violations, setViolations] = useState<ViolationFieldErrors | null>(null);

  async function handleSubmit(values: ConcertFormValues): Promise<void> {
    setFormError(null);
    setViolations(null);
    try {
      await create.mutateAsync(values);
      router.replace("/concerts");
    } catch (error) {
      const rawViolations = violationsFromError(error);
      if (rawViolations) {
        setViolations(mapViolationsToFields(rawViolations));
      } else {
        setFormError(describeConcertError(error));
      }
    }
  }

  return (
    <ScrollView
      testID="new-concert-screen"
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
          Add concert
        </Text>
        <ConcertForm
          initialValues={initialValues}
          onSubmit={handleSubmit}
          submitLabel="Save concert"
          submitting={create.isPending}
          serverViolations={violations}
          formError={formError}
          onCancel={() => router.back()}
        />
      </View>
    </ScrollView>
  );
}
