/**
 * A minimal pub/sub so `lib/auth/authMiddleware.ts` (a request interceptor with no access to
 * React) can tell `SessionProvider` that a background refresh ultimately failed (AC-4.7): the
 * session is no longer valid and the app should land on login exactly once. `SessionProvider`
 * derives its `status` from this rather than the interceptor navigating directly — routing stays a
 * `useSession()`-driven concern (AC-8.3), not something a non-React module reaches into.
 */
type Listener = () => void;

const listeners = new Set<Listener>();

export function onSessionExpired(listener: Listener): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

export function emitSessionExpired(): void {
  for (const listener of listeners) {
    listener();
  }
}
