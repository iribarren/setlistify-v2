/**
 * D-39: a single width breakpoint drives phone vs. desktop layout in one layout file
 * (`app/(app)/_layout.tsx`) — never a `Platform.OS` fork. 900px sits between the canvas's phone
 * and "collapsed rail" bands (`NavShell.dc.html`); this branch collapses the canvas's three desktop
 * bands (persistent sidebar / collapsed rail / tablet drawer) into one, simplifying the shell to a
 * two-state (phone / desktop) layout while keeping the same route tree either way.
 */
export const DESKTOP_BREAKPOINT = 900;
