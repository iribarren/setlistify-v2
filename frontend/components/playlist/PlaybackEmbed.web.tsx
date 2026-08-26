import React, { createElement } from "react";
import { View } from "react-native";

import { useTheme } from "@/theme";

import type { PlaybackEmbedProps } from "./PlaybackEmbedTypes";

/**
 * D-216, web half of the one new platform fork. `react-native-web` renders to the DOM, so a plain
 * intrinsic `<iframe>` is legitimate here (via `createElement`, since the RN JSX namespace doesn't
 * declare `"iframe"`) — same technique as `DateField.web.tsx`'s `<input type="date">`.
 *
 * AC-1.3/D-223: `src` is `url` verbatim — no query parameter appended, stripped or inspected.
 */
export function PlaybackEmbed({ url, onLoad, onUnavailable }: PlaybackEmbedProps): React.JSX.Element {
  const theme = useTheme();

  return (
    // AC-6.4: a fixed, themed, reserved height so the panel never collapses or jumps while the
    // frame loads — 168 is a content-sizing constant (the interior is the provider's, R-10), not a
    // spacing/radius/colour value, and is cheap to change per provider later if prompt 18 needs to.
    <View
      style={{
        width: "100%",
        height: 168,
        borderRadius: theme.rad("md"),
        overflow: "hidden",
        backgroundColor: theme.colors["surface-sunken"],
      }}
    >
      {createElement("iframe", {
        testID: "playback-embed-iframe",
        "data-testid": "playback-embed-iframe",
        src: url,
        loading: "lazy",
        allow: "encrypted-media; clipboard-write; picture-in-picture",
        referrerPolicy: "strict-origin-when-cross-origin",
        title: "Playlist playback",
        onLoad: () => onLoad(),
        onError: () => onUnavailable(),
        style: { width: "100%", height: "100%", border: "none" },
      })}
    </View>
  );
}
