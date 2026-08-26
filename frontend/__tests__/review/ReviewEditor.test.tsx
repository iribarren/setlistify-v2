import React from "react";
import { fireEvent, render, screen } from "@testing-library/react-native";

import { ReviewEditor, type ReviewEditorInitialValue } from "@/components/review/ReviewEditor";
import { ThemeProvider } from "@/theme";

const EMPTY_DRAFT: ReviewEditorInitialValue = { rating: null, notes: "", highlightSongId: null, highlightTitle: "" };

async function renderEditor(overrides: Partial<React.ComponentProps<typeof ReviewEditor>> = {}) {
  const onSave = jest.fn();
  const onCancel = jest.fn();
  const utils = await render(
    <ThemeProvider>
      <ReviewEditor
        testID="review-editor"
        initialValue={EMPTY_DRAFT}
        highlightGroups={[]}
        hasSetlist={false}
        onSave={onSave}
        onCancel={onCancel}
        saving={false}
        {...overrides}
      />
    </ThemeProvider>,
  );
  return { ...utils, onSave, onCancel };
}

describe("ReviewEditor (US-1, US-2, AC-1.6, D-246)", () => {
  it("AC-1.6: Save is disabled with neither a rating nor notes, and enables once notes are typed", async () => {
    await renderEditor();

    expect(screen.getByTestId("review-editor-save")).toBeDisabled();

    await fireEvent.changeText(screen.getByTestId("review-editor-notes"), "Great show.");

    expect(screen.getByTestId("review-editor-save")).not.toBeDisabled();
  });

  it("AC-1.6: a rating alone (no notes) also enables Save — D-231's either/or rule", async () => {
    await renderEditor();

    await fireEvent.press(screen.getByTestId("review-editor-rating-star-4"));

    expect(screen.getByTestId("review-editor-save")).not.toBeDisabled();
  });

  it("saves the composed ConcertReviewInput on Save", async () => {
    const { onSave } = await renderEditor();

    await fireEvent.press(screen.getByTestId("review-editor-rating-star-5"));
    await fireEvent.changeText(screen.getByTestId("review-editor-notes"), "Unreal.");
    await fireEvent.press(screen.getByTestId("review-editor-save"));

    expect(onSave).toHaveBeenCalledWith(
      expect.objectContaining({ rating: 5, notes: "Unreal.", highlightSongId: null, highlightTitle: null }),
    );
  });

  it("AC-1.6/D-246: on a save failure the editor stays open and the draft text is retained", async () => {
    await renderEditor({ saveError: "Couldn't save your review. Try again." });

    await fireEvent.changeText(screen.getByTestId("review-editor-notes"), "Don't lose this.");

    expect(screen.getByTestId("review-editor-error")).toHaveTextContent("Couldn't save your review. Try again.");
    // The editor is still mounted and the draft is still there — nothing discarded it.
    expect(screen.getByTestId("review-editor-notes").props.value).toBe("Don't lose this.");
  });
});
