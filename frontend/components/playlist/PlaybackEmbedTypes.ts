/**
 * D-216: the shared contract for the one new platform fork this feature adds. `PlaybackEmbed.web`
 * is a real `<iframe>`; `PlaybackEmbed.native` renders nothing and reports unavailability on mount.
 * Both export the same props so `PlaybackPanel` stays platform-agnostic.
 */
export interface PlaybackEmbedProps {
  /** `PlaylistOutput.embedUrl`, rendered verbatim — never modified, inspected or templated (D-223). */
  url: string;
  onLoad: () => void;
  onUnavailable: () => void;
}
