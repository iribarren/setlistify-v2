import { MOSTLY_MATCHED_FLOOR, derivePlaylistView } from "@/lib/playlist";
import type { PlaylistGenerationJobOutput } from "@/lib/playlist";

function job(overrides: Partial<PlaylistGenerationJobOutput>): PlaylistGenerationJobOutput {
  return {
    "@id": "/api/playlist-generation-jobs/1",
    "@type": "PlaylistGenerationJob",
    id: 1,
    concertId: 1,
    provider: "spotify",
    mode: "fast",
    state: "queued",
    songsTotal: 0,
    songsProcessed: 0,
    matchedCount: 0,
    lowConfidenceCount: 0,
    notFoundCount: 0,
    skippedCount: 0,
    regionRestrictedCount: 0,
    ...overrides,
  };
}

describe("derivePlaylistView (T-1, D-166/D-170)", () => {
  it("no job at all → idle", () => {
    expect(derivePlaylistView(null, null).kind).toBe("idle");
  });

  it.each(["queued", "resolving_setlist", "matching", "building"])("active state %s → progress", (state) => {
    expect(derivePlaylistView(job({ state }), null).kind).toBe("progress");
  });

  it("expired → idle", () => {
    expect(derivePlaylistView(job({ state: "expired" }), null).kind).toBe("idle");
  });

  it("cancelled → idle", () => {
    expect(derivePlaylistView(job({ state: "cancelled" }), null).kind).toBe("idle");
  });

  it("completed/complete → result_full", () => {
    expect(derivePlaylistView(job({ state: "completed", resultKind: "complete" }), null).kind).toBe("result_full");
  });

  it("completed/partial at/above the floor → result_mostly (T-2 boundary, exactly 0.5)", () => {
    const view = derivePlaylistView(
      job({ state: "completed", resultKind: "partial", matchedCount: 5, songsTotal: 10 }),
      null,
    );
    expect(view.kind).toBe("result_mostly");
  });

  it("completed/partial just below the floor → result_barely (T-2 boundary)", () => {
    const view = derivePlaylistView(
      job({ state: "completed", resultKind: "partial", matchedCount: 4, songsTotal: 10 }),
      null,
    );
    expect(view.kind).toBe("result_barely");
  });

  it("MOSTLY_MATCHED_FLOOR is 0.5", () => {
    expect(MOSTLY_MATCHED_FLOOR).toBe(0.5);
  });

  it("completed/no_tracks_matched → result_nothing", () => {
    expect(derivePlaylistView(job({ state: "completed", resultKind: "no_tracks_matched" }), null).kind).toBe(
      "result_nothing",
    );
  });

  it("completed/no_source_material → a degraded (not a result) variant", () => {
    const view = derivePlaylistView(job({ state: "completed", resultKind: "no_source_material" }), null);
    expect(["degraded_band_unknown", "degraded_no_songs"]).toContain(view.kind);
  });

  it.each([
    ["setlistfm_budget", "blocked_budget"],
    ["provider_quota", "blocked_quota"],
    ["provider_rate_limit", "blocked_quota"],
    ["needs_reauth", "blocked_reauth"],
    ["provider_disabled", "blocked_disabled"],
    ["upstream_unavailable", "blocked_upstream"],
  ])("blocked/%s → %s", (blockedReason, expectedKind) => {
    expect(derivePlaylistView(job({ state: "blocked", blockedReason }), null).kind).toBe(expectedKind);
  });

  it("failed/creation_indeterminate → failed_indeterminate", () => {
    expect(derivePlaylistView(job({ state: "failed", failureReason: "creation_indeterminate" }), null).kind).toBe(
      "failed_indeterminate",
    );
  });

  it.each(["unknown_provider", "block_cycles_exhausted"])("failed/%s → failed_generic", (failureReason) => {
    expect(derivePlaylistView(job({ state: "failed", failureReason }), null).kind).toBe("failed_generic");
  });

  it("blocked_disabled carries the alternative provider (D-175)", () => {
    const view = derivePlaylistView(
      job({ state: "blocked", blockedReason: "provider_disabled", provider: "spotify" }),
      null,
      [
        { "@id": "/api/config/providers/spotify", "@type": "ProviderConfig", key: "spotify", displayName: "Spotify", enabled: false, isDefault: true },
        { "@id": "/api/config/providers/youtube", "@type": "ProviderConfig", key: "youtube", displayName: "YouTube", enabled: true, isDefault: false },
      ],
      [{ provider: "youtube", status: "connected" }],
    );
    expect(view.kind).toBe("blocked_disabled");
    expect(view.alternativeProvider?.key).toBe("youtube");
  });

  it("uses playlist.matchRate over recomputing from job counters when both are present", () => {
    const view = derivePlaylistView(
      job({ state: "completed", resultKind: "partial", matchedCount: 1, songsTotal: 10 }),
      { "@id": "/api/playlists/1", "@type": "Playlist", matchRate: 0.9 },
    );
    // 1/10 would be result_barely; the playlist's own matchRate says otherwise.
    expect(view.kind).toBe("result_mostly");
  });
});
