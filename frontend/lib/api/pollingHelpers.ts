/**
 * D-163's termination rule, factored out so every `Retry-After`-driven poller in the app shares
 * it: presence of the header seeds the next refetch interval (ms); its absence stops polling
 * entirely. `usePlaylistJobPolling` (`lib/playlist/polling.ts`) and `useSetlistRefreshPolling`
 * (`lib/setlistRefresh/polling.ts`, AC-10.4, docs/specs/2026-08-27-instant-setlist-refresh.md) both
 * call this rather than re-deriving it — the one piece of the polling contract the two pollers
 * could share without one hook depending on data shapes (ETag/304 handling, response types) that
 * only make sense for one of the two endpoints.
 */
export function retryAfterMs(header: string | null): number | false {
  return header ? Math.max(1, Number(header)) * 1000 : false;
}
