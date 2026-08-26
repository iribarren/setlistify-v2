import React, { useState } from "react";
import { Modal, Text, useWindowDimensions, View } from "react-native";

import { Button, Card, TextInput } from "@/components";
// Imports the concrete file (not the `@/components/concert` barrel, which pulls in `ConcertCard` —
// that component renders this feature's `StarRating`, so going through the barrel here would form
// an import cycle between the two directories).
import { DisclosureSection } from "@/components/concert/DisclosureSection";
import { DESKTOP_BREAKPOINT } from "@/components/nav";
import { countGraphemes, NOTES_MAX, type ConcertReviewInput, type HighlightBandGroup } from "@/lib/review";
import { useTheme } from "@/theme";

import { HighlightPicker, type HighlightValue } from "./HighlightPicker";
import { StarRating } from "./StarRating";

export interface ReviewEditorInitialValue {
  rating: number | null;
  notes: string;
  highlightSongId: number | null;
  highlightTitle: string;
}

export interface ReviewEditorProps {
  initialValue: ReviewEditorInitialValue;
  highlightGroups: HighlightBandGroup[];
  hasSetlist: boolean;
  onSave: (input: ConcertReviewInput) => void;
  onCancel: () => void;
  saving: boolean;
  /** AC-1.6/D-246: a failed save's message — the editor stays open and the draft stays intact. */
  saveError?: string | null;
  testID?: string;
}

/**
 * `ReviewEditor` — US-1/US-2. AC-1.3: rating + notes + a collapsed highlight disclosure. AC-1.3/
 * D-39/D-245: sheet on phone widths (an RN `Modal`, the one cross-platform overlay primitive — no
 * new platform fork), inline expansion on desktop widths — a WIDTH breakpoint, never `Platform.OS`.
 * AC-1.4: the notes field autofocuses. AC-1.6: Save is disabled while the draft is an empty review
 * (D-231's rule, mirrored client-side, advisory only). AC-1.6/D-246: this component is purely a
 * controlled draft — a failed save (surfaced by the caller via `saveError`) never clears local
 * state, so the sheet staying mounted is what "keeps the draft" (nothing here discards on error).
 */
export function ReviewEditor({
  initialValue,
  highlightGroups,
  hasSetlist,
  onSave,
  onCancel,
  saving,
  saveError,
  testID,
}: ReviewEditorProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const { width } = useWindowDimensions();
  const isDesktop = width >= DESKTOP_BREAKPOINT;

  const [rating, setRating] = useState<number | null>(initialValue.rating);
  const [notes, setNotes] = useState(initialValue.notes);
  const [highlight, setHighlight] = useState<HighlightValue>({
    songId: initialValue.highlightSongId,
    title: initialValue.highlightTitle,
  });

  const trimmedNotes = notes.trim();
  const canSave = rating != null || trimmedNotes.length > 0; // D-231, mirrored (AC-1.6).
  const noteCount = countGraphemes(notes);
  const overNoteLimit = noteCount > NOTES_MAX;

  function handleSave(): void {
    if (!canSave || saving) {
      return;
    }
    onSave({
      rating,
      notes: trimmedNotes.length > 0 ? notes : null,
      highlightSongId: highlight.songId,
      highlightTitle: highlight.title.trim().length > 0 ? highlight.title.trim() : null,
    });
  }

  const content = (
    <View testID={testID} style={{ gap: theme.space("space-4") }}>
      <Text
        style={{
          color: colors["text-primary"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize: theme.typeScale.lg.fontSize,
        }}
      >
        What was it like?
      </Text>

      {saveError ? (
        <Text
          testID={testID ? `${testID}-error` : undefined}
          accessibilityRole="alert"
          style={{ color: colors["error-strong"], fontSize: theme.typeScale.sm.fontSize }}
        >
          {saveError}
        </Text>
      ) : null}

      <StarRating testID={testID ? `${testID}-rating` : undefined} value={rating} onChange={setRating} />

      <View style={{ gap: theme.space("space-1") }}>
        <TextInput
          testID={testID ? `${testID}-notes` : undefined}
          label="Notes"
          value={notes}
          onChangeText={setNotes}
          placeholder="What happened, what it felt like, anything worth remembering…"
          multiline
          numberOfLines={5}
          autoFocus
          errorMessage={overNoteLimit ? `Notes are at most ${NOTES_MAX} characters.` : undefined}
        />
        <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize, alignSelf: "flex-end" }}>
          {noteCount} / {NOTES_MAX}
        </Text>
      </View>

      <DisclosureSection testID={testID ? `${testID}-highlight` : undefined} title="Best song of the night">
        <HighlightPicker
          testID={testID ? `${testID}-highlight-picker` : undefined}
          groups={highlightGroups}
          hasSetlist={hasSetlist}
          value={highlight}
          onChange={setHighlight}
        />
      </DisclosureSection>

      <View style={{ flexDirection: "row", gap: theme.space("space-3"), justifyContent: "flex-end" }}>
        <Button testID={testID ? `${testID}-cancel` : undefined} label="Cancel" variant="secondary" onPress={onCancel} disabled={saving} />
        <Button
          testID={testID ? `${testID}-save` : undefined}
          label={saving ? "Saving…" : "Save"}
          onPress={handleSave}
          disabled={!canSave || saving}
        />
      </View>
    </View>
  );

  if (isDesktop) {
    return <Card>{content}</Card>;
  }

  return (
    <Modal
      testID={testID ? `${testID}-sheet` : undefined}
      visible
      transparent
      animationType="slide"
      onRequestClose={onCancel}
    >
      <View style={{ flex: 1, justifyContent: "flex-end", backgroundColor: "rgba(0,0,0,0.4)" }}>
        <View
          style={{
            backgroundColor: colors["surface-raised"],
            borderTopLeftRadius: theme.rad("lg"),
            borderTopRightRadius: theme.rad("lg"),
            padding: theme.space("space-5"),
            maxHeight: "90%",
          }}
        >
          {content}
        </View>
      </View>
    </Modal>
  );
}
