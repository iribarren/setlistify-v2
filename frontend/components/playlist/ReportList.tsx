import React from "react";
import { Text, View } from "react-native";

import { describeReportCode, type PlaylistTrackOutput, type ReportEntryOutput } from "@/lib/playlist";
import { useTheme } from "@/theme";

export interface ReportListProps {
  /** AC-5.5: job-level codes (e.g. `BANDS_OMITTED_FOR_LENGTH`) render as a short note above the list. */
  summary: ReportEntryOutput[];
  /** AC-5.1: only the songs that need a look — matched rows are never rendered here. */
  tracks: PlaylistTrackOutput[];
  testID?: string;
}

const NEEDS_ATTENTION: ReadonlySet<string> = new Set([
  "matched_low_confidence",
  "not_found",
  "region_restricted",
]);

/**
 * `Report.dc.html`. AC-5.1: a 25-song setlist with 3 gaps is a 3-row screen — filtered here, not by
 * the caller. AC-5.2: sentences come from `reportCopy.ts`, keyed by `reasonCode`. AC-5.3: no raw
 * code/enum/status ever reaches the tree — `describeReportCode` is the only thing rendered.
 */
export function ReportList({ summary, tracks, testID }: ReportListProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const rows = tracks.filter((track) => NEEDS_ATTENTION.has(track.outcome ?? ""));

  return (
    <View testID={testID} style={{ gap: theme.space("space-4") }}>
      {summary.length > 0 ? (
        <View style={{ gap: theme.space("space-1") }}>
          {summary.map((entry, index) => (
            <Text
              key={`${entry.code}-${index}`}
              style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize, lineHeight: theme.typeScale.xs.lineHeight }}
            >
              {describeReportCode(entry.code, entry.params)}
            </Text>
          ))}
        </View>
      ) : null}

      {rows.length === 0 ? (
        <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.sm.fontSize }}>
          Nothing needs a look — every song matched.
        </Text>
      ) : (
        <View style={{ gap: 0 }}>
          {rows.map((track, index) => (
            <View
              key={`${track.ordinal}-${index}`}
              testID={testID ? `${testID}-row-${track.ordinal}` : undefined}
              style={{
                flexDirection: "row",
                gap: theme.space("space-3"),
                paddingVertical: theme.space("space-3"),
                borderBottomWidth: index === rows.length - 1 ? 0 : 1,
                borderBottomColor: colors["border-subtle"],
              }}
            >
              <Text style={{ fontFamily: theme.resolveFontFamily("mono", "regular"), fontSize: theme.typeScale.xs.fontSize, color: colors["text-tertiary"], width: 24 }}>
                {String((track.sourcePosition ?? 0) + 1).padStart(2, "0")}
              </Text>
              <View style={{ flex: 1 }}>
                <Text style={{ color: colors["text-primary"], fontFamily: theme.resolveFontFamily("body", "semibold"), fontSize: theme.typeScale.sm.fontSize }}>
                  {track.sourceTitle}
                </Text>
                <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize, marginTop: 2, lineHeight: theme.typeScale.xs.lineHeight }}>
                  {describeReportCode(track.reasonCode, track.reasonParams)}
                </Text>
              </View>
            </View>
          ))}
        </View>
      )}
    </View>
  );
}
