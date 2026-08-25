import { act, renderHook, waitFor } from "@testing-library/react-native";

import { clearPlaylistChoiceDraft, usePlaylistChoiceDraft } from "@/lib/playlist";

describe("usePlaylistChoiceDraft (D-206/AC-6.1/AC-6.2)", () => {
  it("starts empty and loaded for a job id with no persisted draft", async () => {
    const { result } = await renderHook(() => usePlaylistChoiceDraft("job-1"));
    await waitFor(() => expect(result.current.loaded).toBe(true));
    expect(result.current.draft).toEqual({ setlistChoices: {}, versionChoices: {} });
  });

  it("records a setlist choice and a version choice", async () => {
    const { result } = await renderHook(() => usePlaylistChoiceDraft("job-2"));
    await waitFor(() => expect(result.current.loaded).toBe(true));

    await act(async () => {
      result.current.setSetlistChoice(7, "setlistfm-abc");
    });
    await act(async () => {
      result.current.setVersionChoice(3, "track-xyz");
    });

    expect(result.current.draft.setlistChoices).toEqual({ 7: "setlistfm-abc" });
    expect(result.current.draft.versionChoices).toEqual({ 3: "track-xyz" });
  });

  it("records an explicit decline (null) distinctly from an unanswered decision (AC-2.6)", async () => {
    const { result } = await renderHook(() => usePlaylistChoiceDraft("job-3"));
    await waitFor(() => expect(result.current.loaded).toBe(true));

    await act(async () => {
      result.current.setVersionChoice(1, null);
    });

    expect(1 in result.current.draft.versionChoices).toBe(true);
    expect(result.current.draft.versionChoices[1]).toBeNull();
  });

  it("survives a remount (AC-6.2: backgrounding/reload/app-restart before submission)", async () => {
    const first = await renderHook(() => usePlaylistChoiceDraft("job-4"));
    await waitFor(() => expect(first.result.current.loaded).toBe(true));
    await act(async () => {
      first.result.current.setVersionChoice(9, "track-9");
    });
    await act(async () => {
      first.unmount();
    });

    const second = await renderHook(() => usePlaylistChoiceDraft("job-4"));
    await waitFor(() => expect(second.result.current.loaded).toBe(true));
    expect(second.result.current.draft.versionChoices).toEqual({ 9: "track-9" });
  });

  it("clear() empties the in-memory draft and the persisted store", async () => {
    const { result } = await renderHook(() => usePlaylistChoiceDraft("job-5"));
    await waitFor(() => expect(result.current.loaded).toBe(true));
    await act(async () => {
      result.current.setSetlistChoice(1, "abc");
    });
    await act(async () => {
      result.current.clear();
    });
    expect(result.current.draft).toEqual({ setlistChoices: {}, versionChoices: {} });

    const remount = await renderHook(() => usePlaylistChoiceDraft("job-5"));
    await waitFor(() => expect(remount.result.current.loaded).toBe(true));
    expect(remount.result.current.draft).toEqual({ setlistChoices: {}, versionChoices: {} });
  });

  it("clearPlaylistChoiceDraft() clears a draft by job id directly (D-206: cleared on submit/cancel/expiry)", async () => {
    const { result } = await renderHook(() => usePlaylistChoiceDraft("job-6"));
    await waitFor(() => expect(result.current.loaded).toBe(true));
    await act(async () => {
      result.current.setVersionChoice(2, "track-2");
    });

    await act(async () => {
      await clearPlaylistChoiceDraft("job-6");
    });

    const remount = await renderHook(() => usePlaylistChoiceDraft("job-6"));
    await waitFor(() => expect(remount.result.current.loaded).toBe(true));
    expect(remount.result.current.draft).toEqual({ setlistChoices: {}, versionChoices: {} });
  });

  it("switching job ids loads that job's own draft, not the previous one", async () => {
    const seed = await renderHook(() => usePlaylistChoiceDraft("job-7"));
    await waitFor(() => expect(seed.result.current.loaded).toBe(true));
    await act(async () => {
      seed.result.current.setSetlistChoice(1, "only-for-job-7");
    });
    await act(async () => {
      seed.unmount();
    });

    const { result, rerender } = await renderHook(({ jobId }: { jobId: string | null }) => usePlaylistChoiceDraft(jobId), {
      initialProps: { jobId: "job-8" },
    });
    await waitFor(() => expect(result.current.loaded).toBe(true));
    expect(result.current.draft.setlistChoices).toEqual({});

    await act(async () => {
      rerender({ jobId: "job-7" });
    });
    await waitFor(() => expect(result.current.draft.setlistChoices).toEqual({ 1: "only-for-job-7" }));
  });
});
