// D-236/AC-9.2: the server counts `notes`/`highlightTitle` in grapheme clusters
// (`Assert\Length(countUnit: COUNT_GRAPHEMES)`), so a family emoji (`👨‍👩‍👧‍👦`) costs 1, not 7. This
// mirrors that count client-side, advisory only — the server is authoritative, so a client
// miscount (e.g. `Intl.Segmenter` unavailable) never blocks a save; it only shades the UI's
// remaining-count hint. A 422 from the server is always the source of truth (AC-9.3).
export const NOTES_MAX = 4000;
export const HIGHLIGHT_TITLE_MAX = 200;

interface SegmenterLike {
  segment(input: string): Iterable<unknown>;
}

function hasSegmenter(intl: typeof Intl): intl is typeof Intl & { Segmenter: new (locale?: string, options?: { granularity: string }) => SegmenterLike } {
  return typeof (intl as { Segmenter?: unknown }).Segmenter === "function";
}

/** AC-9.2: `Intl.Segmenter` where available, `[...str].length` (code points) as the fallback. */
export function countGraphemes(text: string): number {
  if (typeof Intl !== "undefined" && hasSegmenter(Intl)) {
    try {
      const segmenter = new Intl.Segmenter(undefined, { granularity: "grapheme" });
      let count = 0;
      // eslint-disable-next-line @typescript-eslint/no-unused-vars
      for (const _segment of segmenter.segment(text)) {
        count += 1;
      }
      return count;
    } catch {
      // Falls through to the code-point fallback below.
    }
  }
  return [...text].length;
}
