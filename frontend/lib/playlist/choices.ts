import { useCallback, useEffect, useRef, useState } from "react";

import { choicesStorage } from "./choicesStorage";

/**
 * D-206/AC-6.1/AC-6.2: the client's own model of "what I've decided so far", per job id. This is a
 * convenience cache only — the server is authoritative the moment a submission succeeds (AC-6.5), and
 * a 422 always triggers a refetch-and-rerender rather than reconciling this draft.
 */
export interface PlaylistChoiceDraft {
  /** bandId -> chosen setlistfmId (US-1's setlist-selection step). */
  setlistChoices: Record<number, string>;
  /** sourcePosition -> chosen providerTrackId, or `null` for an explicit "none of these" (AC-2.6). */
  versionChoices: Record<number, string | null>;
}

const EMPTY_DRAFT: PlaylistChoiceDraft = { setlistChoices: {}, versionChoices: {} };

function draftKey(jobId: string): string {
  return `setlistify.playlist.draft.${jobId}`;
}

async function loadDraft(jobId: string): Promise<PlaylistChoiceDraft> {
  try {
    const raw = await choicesStorage.getItem(draftKey(jobId));
    if (!raw) {
      return EMPTY_DRAFT;
    }
    const parsed = JSON.parse(raw) as Partial<PlaylistChoiceDraft>;
    return {
      setlistChoices: parsed.setlistChoices ?? {},
      versionChoices: parsed.versionChoices ?? {},
    };
  } catch {
    // A corrupt or inaccessible store degrades to "no draft" — never a crash (D-206: convenience only).
    return EMPTY_DRAFT;
  }
}

async function persistDraft(jobId: string, draft: PlaylistChoiceDraft): Promise<void> {
  try {
    await choicesStorage.setItem(draftKey(jobId), JSON.stringify(draft));
  } catch {
    // Best-effort — a write failure never blocks the flow.
  }
}

/** Cleared on successful submission, on cancel, and on expiry (D-206). */
export async function clearPlaylistChoiceDraft(jobId: string): Promise<void> {
  try {
    await choicesStorage.removeItem(draftKey(jobId));
  } catch {
    // Best-effort.
  }
}

export interface UsePlaylistChoiceDraftResult {
  draft: PlaylistChoiceDraft;
  /** `false` until the persisted draft (if any) has been read back for this job id. */
  loaded: boolean;
  setSetlistChoice: (bandId: number, setlistfmId: string) => void;
  setVersionChoice: (sourcePosition: number, providerTrackId: string | null) => void;
  /** Removes a version decision entirely (distinct from declining it — AC-2.6 vs. simply undecided). */
  clearVersionChoice: (sourcePosition: number) => void;
  clear: () => void;
}

/**
 * AC-6.1/AC-6.2: holds the in-progress setlist/version choices for one job, persisted so
 * backgrounding, a reload, or an app restart before submission never loses them. Keyed by job id —
 * switching jobs (a new generation, or resuming a different one) loads that job's own draft.
 */
export function usePlaylistChoiceDraft(jobId: string | null): UsePlaylistChoiceDraftResult {
  const [draft, setDraftState] = useState<PlaylistChoiceDraft>(EMPTY_DRAFT);
  const [loaded, setLoaded] = useState(jobId == null);
  const draftRef = useRef(draft);
  const jobIdRef = useRef(jobId);

  // A changed job id invalidates the draft immediately, adjusted during render (React's documented
  // pattern for resetting state on a prop change) rather than in an effect, which would otherwise
  // call setState synchronously in the effect body and cascade an extra render.
  const [trackedJobId, setTrackedJobId] = useState(jobId);
  if (trackedJobId !== jobId) {
    setTrackedJobId(jobId);
    setDraftState(EMPTY_DRAFT);
    setLoaded(jobId == null);
  }

  useEffect(() => {
    jobIdRef.current = jobId;
    if (!jobId) {
      draftRef.current = EMPTY_DRAFT;
      return;
    }
    let cancelled = false;
    void loadDraft(jobId).then((loadedDraft) => {
      if (!cancelled) {
        draftRef.current = loadedDraft;
        setDraftState(loadedDraft);
        setLoaded(true);
      }
    });
    return () => {
      cancelled = true;
    };
  }, [jobId]);

  const applyPatch = useCallback((patch: Partial<PlaylistChoiceDraft>) => {
    const next: PlaylistChoiceDraft = {
      setlistChoices: { ...draftRef.current.setlistChoices, ...patch.setlistChoices },
      versionChoices: { ...draftRef.current.versionChoices, ...patch.versionChoices },
    };
    draftRef.current = next;
    setDraftState(next);
    if (jobIdRef.current) {
      void persistDraft(jobIdRef.current, next);
    }
  }, []);

  const setSetlistChoice = useCallback(
    (bandId: number, setlistfmId: string) => applyPatch({ setlistChoices: { [bandId]: setlistfmId } }),
    [applyPatch],
  );

  const setVersionChoice = useCallback(
    (sourcePosition: number, providerTrackId: string | null) =>
      applyPatch({ versionChoices: { [sourcePosition]: providerTrackId } }),
    [applyPatch],
  );

  const clearVersionChoice = useCallback((sourcePosition: number) => {
    const { [sourcePosition]: _removed, ...rest } = draftRef.current.versionChoices;
    const next: PlaylistChoiceDraft = { ...draftRef.current, versionChoices: rest };
    draftRef.current = next;
    setDraftState(next);
    if (jobIdRef.current) {
      void persistDraft(jobIdRef.current, next);
    }
  }, []);

  const clear = useCallback(() => {
    draftRef.current = EMPTY_DRAFT;
    setDraftState(EMPTY_DRAFT);
    if (jobIdRef.current) {
      void clearPlaylistChoiceDraft(jobIdRef.current);
    }
  }, []);

  return { draft, loaded, setSetlistChoice, setVersionChoice, clearVersionChoice, clear };
}
