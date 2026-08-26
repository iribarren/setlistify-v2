import AsyncStorage from "@react-native-async-storage/async-storage";
import { act, renderHook, waitFor } from "@testing-library/react-native";

import type { CachedConcert } from "@/lib/concerts";
import { useReviewPromptCard } from "@/lib/review";

function concert(overrides: Partial<CachedConcert> = {}): CachedConcert {
  return {
    id: 1,
    date: "2026-08-20",
    timezone: "Europe/Madrid",
    status: "past",
    lineup: [],
    venue: {},
    reviewSummary: null,
    createdAt: "2026-01-01T00:00:00+00:00",
    updatedAt: "2026-01-01T00:00:00+00:00",
    ...overrides,
  } as CachedConcert;
}

describe("useReviewPromptCard (US-7, AC-7.1-AC-7.4)", () => {
  afterEach(async () => {
    await AsyncStorage.clear();
  });

  it("AC-7.1/AC-7.2: picks the eligible past concert once the list is ready", async () => {
    const { result } = await renderHook(() => useReviewPromptCard([concert()], true));

    await waitFor(() => expect(result.current.concert?.id).toBe(1));
  });

  it("does not pick anything while `ready` is false", async () => {
    let ready = false;
    const { result, rerender } = await renderHook(() => useReviewPromptCard([concert()], ready));

    expect(result.current.concert).toBeNull();

    ready = true;
    await rerender(undefined);
    await waitFor(() => expect(result.current.concert?.id).toBe(1));
  });

  it("AC-7.3: dismiss() hides the card and it stays hidden across a remount for the same concert", async () => {
    const { result, unmount } = await renderHook(() => useReviewPromptCard([concert()], true));
    await waitFor(() => expect(result.current.concert?.id).toBe(1));

    await act(async () => {
      result.current.dismiss();
    });
    await waitFor(() => expect(result.current.concert).toBeNull());
    await act(async () => {
      unmount();
    });

    // A fresh mount (e.g. reopening the concert list) re-reads the persisted dismissal.
    const remount = await renderHook(() => useReviewPromptCard([concert()], true));
    await waitFor(() => expect(remount.result.current.concert).toBeNull());
  });

  it("AC-7.4: a concert that stops being a candidate (a review was written) drops its card without a dismissal", async () => {
    let list: CachedConcert[] = [concert()];
    const { result, rerender } = await renderHook(() => useReviewPromptCard(list, true));
    await waitFor(() => expect(result.current.concert?.id).toBe(1));

    list = [{ ...list[0], reviewSummary: { rating: 5, highlightTitle: null, updatedAt: "x" } }];
    await rerender(undefined);

    await waitFor(() => expect(result.current.concert).toBeNull());
  });
});
