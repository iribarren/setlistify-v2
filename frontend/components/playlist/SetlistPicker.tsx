import React from "react";
import { Linking, Pressable, Text, View } from "react-native";

import { Badge, Button } from "@/components";
import type { CandidateSetlistBandOutput, CandidateSetlistOutput, CandidateSetlistsOutput } from "@/lib/playlist";
import { useTheme } from "@/theme";

export interface SetlistSelection {
  bandId: number;
  setlistfmId: string;
}

export interface SetlistPickerProps {
  candidateSetlists: CandidateSetlistsOutput;
  /** Draft overrides (`choices.ts`), band id -> chosen setlistfmId. */
  choices: Record<number, string>;
  onChoose: (bandId: number, setlistfmId: string) => void;
  onSubmit: (selections: SetlistSelection[]) => void;
  submitting: boolean;
  testID?: string;
}

/** AC-1.4: "Same night" wins; otherwise D-132's automatic pick (`recommendedSetlistfmId`). */
function defaultSetlistFor(band: CandidateSetlistBandOutput): string | null {
  const sameNight = (band.candidates ?? []).find((candidate) => candidate.isSameNight);
  if (sameNight) {
    return sameNight.setlistfmId ?? null;
  }
  return band.recommendedSetlistfmId ?? null;
}

function isQualifying(band: CandidateSetlistBandOutput): boolean {
  return !band.noSetlistCause && (band.candidates ?? []).length > 0 && band.bandId != null;
}

/**
 * `SetlistSelect.dc.html` (US-1). Multi-band (AC-1.7): every qualifying band gets its own section —
 * one submission covers all of them. A band with `noSetlistCause` renders as an explanatory row, not
 * a question (AC-1.8). Submit is blocked until every qualifying band has a selection (AC-1.7).
 */
export function SetlistPicker({
  candidateSetlists,
  choices,
  onChoose,
  onSubmit,
  submitting,
  testID,
}: SetlistPickerProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const bands = candidateSetlists.bands ?? [];
  const qualifyingBands = bands.filter(isQualifying);

  function effectiveSelection(band: CandidateSetlistBandOutput): string | null {
    return (band.bandId != null ? choices[band.bandId] : undefined) ?? defaultSetlistFor(band);
  }

  const allAnswered = qualifyingBands.every((band) => effectiveSelection(band) != null);

  function handleSubmit(): void {
    const selections = qualifyingBands
      .map((band) => {
        const setlistfmId = effectiveSelection(band);
        return setlistfmId && band.bandId != null ? { bandId: band.bandId, setlistfmId } : null;
      })
      .filter((selection): selection is SetlistSelection => selection !== null);
    onSubmit(selections);
  }

  return (
    <View testID={testID} style={{ gap: theme.space("space-5") }}>
      <Text
        style={{
          color: colors["text-primary"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize: theme.typeScale.lg.fontSize,
        }}
      >
        Which night?
      </Text>

      {bands.map((band) =>
        isQualifying(band) && band.bandId != null ? (
          <BandSection
            key={band.bandId}
            band={band}
            selected={effectiveSelection(band)}
            onSelect={(setlistfmId) => onChoose(band.bandId as number, setlistfmId)}
            testID={testID ? `${testID}-band-${band.bandId}` : undefined}
          />
        ) : (
          <View
            key={band.bandId}
            testID={testID ? `${testID}-band-${band.bandId}-unavailable` : undefined}
            style={{ gap: theme.space("space-1") }}
          >
            <Text style={{ color: colors["text-primary"], fontFamily: theme.resolveFontFamily("body", "semibold"), fontSize: theme.typeScale.sm.fontSize }}>
              {band.bandName}
            </Text>
            <Text style={{ color: colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>
              No usable setlist found for this band — it won&apos;t be part of the playlist.
            </Text>
          </View>
        ),
      )}

      <Button
        testID={testID ? `${testID}-submit` : undefined}
        label={submitting ? "Starting…" : "Continue"}
        onPress={handleSubmit}
        disabled={submitting || !allAnswered}
      />
    </View>
  );
}

interface BandSectionProps {
  band: CandidateSetlistBandOutput;
  selected: string | null;
  onSelect: (setlistfmId: string) => void;
  testID?: string;
}

function BandSection({ band, selected, onSelect, testID }: BandSectionProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;

  return (
    <View testID={testID} style={{ gap: theme.space("space-2") }}>
      <Text style={{ color: colors["text-primary"], fontFamily: theme.resolveFontFamily("body", "semibold"), fontSize: theme.typeScale.sm.fontSize }}>
        {band.bandName}
      </Text>
      <View style={{ gap: theme.space("space-2") }}>
        {(band.candidates ?? [])
          .filter((candidate): candidate is CandidateSetlistOutput & { setlistfmId: string } => candidate.setlistfmId != null)
          .map((candidate) => (
            <CandidateRow
              key={candidate.setlistfmId}
              candidate={candidate}
              recommendedReason={band.recommendedSetlistfmId === candidate.setlistfmId ? (band.recommendedReason ?? null) : null}
              selected={selected === candidate.setlistfmId}
              onPress={() => onSelect(candidate.setlistfmId)}
              testID={testID ? `${testID}-candidate-${candidate.setlistfmId}` : undefined}
            />
          ))}
      </View>
    </View>
  );
}

interface CandidateRowProps {
  candidate: CandidateSetlistOutput;
  recommendedReason: string | null;
  selected: boolean;
  onPress: () => void;
  testID?: string;
}

function CandidateRow({ candidate, recommendedReason, selected, onPress, testID }: CandidateRowProps): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const place = [candidate.venueName, candidate.cityName].filter(Boolean).join(", ");

  return (
    <Pressable
      testID={testID}
      onPress={onPress}
      accessibilityRole="radio"
      accessibilityState={{ checked: selected }}
      style={{
        flexDirection: "row",
        alignItems: "center",
        gap: theme.space("space-3"),
        padding: theme.space("space-3"),
        borderRadius: theme.rad("md"),
        borderWidth: 1.5,
        borderColor: selected ? colors["accent-primary-strong"] : colors["border-subtle"],
        backgroundColor: selected ? `${colors["info-bright"]}1a` : colors["surface-raised"],
      }}
    >
      <View style={{ flex: 1, gap: 2 }}>
        <View style={{ flexDirection: "row", alignItems: "center", gap: theme.space("space-2"), flexWrap: "wrap" }}>
          <Text style={{ color: colors["text-primary"], fontFamily: theme.resolveFontFamily("body", "semibold"), fontSize: theme.typeScale.sm.fontSize }}>
            {candidate.eventDate}
          </Text>
          {candidate.isSameNight ? <Badge label="Same night" variant="success" /> : null}
          {!candidate.isSameNight && recommendedReason ? <Badge label={recommendedReason} variant="info" /> : null}
        </View>
        <Text style={{ color: colors["text-secondary"], fontSize: theme.typeScale.xs.fontSize }}>
          {place || "Venue unknown"} · {candidate.songCount} songs
        </Text>
        {candidate.url ? (
          <Pressable onPress={() => void Linking.openURL(candidate.url as string)}>
            <Text style={{ color: colors["info-strong"], fontSize: theme.typeScale.xs.fontSize, textDecorationLine: "underline" }}>
              View on setlist.fm
            </Text>
          </Pressable>
        ) : null}
      </View>
    </Pressable>
  );
}
