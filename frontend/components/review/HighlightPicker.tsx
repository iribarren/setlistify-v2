import React from "react";
import { Pressable, Text, View } from "react-native";

import { TextInput } from "@/components";
import { HIGHLIGHT_TITLE_MAX, type HighlightBandGroup } from "@/lib/review";
import { useTheme } from "@/theme";

export interface HighlightValue {
  songId: number | null;
  title: string;
}

export interface HighlightPickerProps {
  /** One entry per band with a matching, non-empty cached setlist (AC-5.1), in lineup order. */
  groups: HighlightBandGroup[];
  /** AC-5.1/AC-5.2: the picker only renders when there's at least one group; otherwise a plain field. */
  hasSetlist: boolean;
  value: HighlightValue;
  onChange: (value: HighlightValue) => void;
  testID?: string;
}

/**
 * `HighlightPicker` — US-5. AC-5.1: a picker grouped by band, in setlist order, with a free-text
 * "Something else…" escape hatch at the bottom, when the concert has at least one persisted setlist
 * for a band in its lineup. AC-5.2: otherwise a plain text field — no explanation for the missing
 * picker, since `PlaylistSection` already covers that case directly above this one. AC-5.3:
 * selecting from the picker sets both `songId` and a title snapshot; typing sets the title alone.
 */
export function HighlightPicker({ groups, hasSetlist, value, onChange, testID }: HighlightPickerProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;

  if (!hasSetlist) {
    return (
      <TextInput
        testID={testID ? `${testID}-freetext` : undefined}
        label="Best song of the night"
        value={value.title}
        onChangeText={(text) => onChange({ songId: null, title: text })}
        placeholder="e.g. the encore, or a song that hit different live"
      />
    );
  }

  return (
    <View testID={testID} style={{ gap: theme.space("space-4") }}>
      {groups.map((group) => (
        <View key={group.bandId} style={{ gap: theme.space("space-2") }}>
          <Text
            style={{
              color: colors["text-primary"],
              fontFamily: theme.resolveFontFamily("body", "semibold"),
              fontSize: theme.typeScale.sm.fontSize,
            }}
          >
            {group.bandName}
          </Text>
          <View style={{ gap: theme.space("space-1") }}>
            {group.songs.map((song) => {
              const selected = value.songId === song.songId;
              return (
                <Pressable
                  key={song.songId}
                  testID={testID ? `${testID}-song-${song.songId}` : undefined}
                  onPress={() => onChange({ songId: song.songId, title: song.title })}
                  accessibilityRole="radio"
                  accessibilityState={{ checked: selected }}
                  style={{
                    minHeight: 44,
                    justifyContent: "center",
                    paddingHorizontal: theme.space("space-3"),
                    borderRadius: theme.rad("md"),
                    borderWidth: 1.5,
                    borderColor: selected ? colors["accent-primary-strong"] : colors["border-subtle"],
                    backgroundColor: selected ? `${colors["info-bright"]}1a` : colors["surface-raised"],
                  }}
                >
                  <Text style={{ color: colors["text-primary"], fontSize: theme.typeScale.sm.fontSize }}>
                    {song.title}
                  </Text>
                </Pressable>
              );
            })}
          </View>
        </View>
      ))}

      <View style={{ gap: theme.space("space-2") }}>
        <Text
          style={{
            color: colors["text-primary"],
            fontFamily: theme.resolveFontFamily("body", "semibold"),
            fontSize: theme.typeScale.sm.fontSize,
          }}
        >
          Something else…
        </Text>
        <TextInput
          testID={testID ? `${testID}-freetext` : undefined}
          label="Or name it yourself"
          value={value.songId == null ? value.title : ""}
          onChangeText={(text) => onChange({ songId: null, title: text })}
          placeholder={`Up to ${HIGHLIGHT_TITLE_MAX} characters`}
        />
      </View>
    </View>
  );
}
