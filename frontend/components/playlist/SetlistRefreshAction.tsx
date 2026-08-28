import React, { useEffect, useState } from "react";
import { Text, View } from "react-native";

import { Badge, Button, ListRow } from "@/components";
import {
  asBandResolutionState,
  asRefreshState,
  asRefusedReason,
  formatRetryAt,
  pickRefusalCopy,
  pickRefusalReason,
  refusalCopy,
  useResolveBandIdentity,
  useSetlistRefreshPolling,
  useTriggerSetlistRefresh,
  type BandSearchCandidateOutput,
} from "@/lib/setlistRefresh";
import { useTheme } from "@/theme";

export interface SetlistRefreshActionProps {
  bandId: number;
  bandName: string;
  /** Called after a refresh or a pick lands on a terminal, band-improving outcome, so the caller
   * can refetch the job/playlist and show the new result (AC-10.9). */
  onBandResolved?: () => void;
  testID?: string;
}

function useCountdown(target: string | null | undefined): string | null {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (!target) {
      return;
    }
    const interval = setInterval(() => setNow(Date.now()), 30_000);
    return () => clearInterval(interval);
  }, [target]);

  if (!target) {
    return null;
  }
  const ms = new Date(target).getTime() - now;
  if (Number.isNaN(ms) || ms <= 0) {
    return null;
  }
  return formatRetryAt(target);
}

/**
 * docs/specs/2026-08-27-instant-setlist-refresh.md, US-10 (D-269): the one conditional action on
 * the existing playlist result screen. AC-10.1/10.2 (entitlement + a helpable `noSetlistCause`) are
 * checked by the caller before this mounts at all — this component assumes both are already true
 * for `bandId`.
 *
 * One instance per affected band (`bandsNeedingSetlist()`, `lib/setlistRefresh/fromReport.ts`).
 */
export function SetlistRefreshAction({
  bandId,
  bandName,
  onBandResolved,
  testID,
}: SetlistRefreshActionProps): React.JSX.Element {
  const theme = useTheme();
  const poll = useSetlistRefreshPolling(bandId);
  const trigger = useTriggerSetlistRefresh(bandId);
  const resolve = useResolveBandIdentity(bandId);
  const [selectedMbid, setSelectedMbid] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [pickError, setPickError] = useState<"mbid_not_a_candidate" | "band_already_resolved" | null>(null);

  const record = poll.data;
  const state = asRefreshState(record?.state);
  const refusedReason = asRefusedReason(record?.refusedReason);
  const resolutionAfter = asBandResolutionState(record?.bandResolutionStateAfter);
  const cooldownCountdown = useCountdown(record?.cooldownUntil);

  const prevStateRef = React.useRef<string | null | undefined>(undefined);
  useEffect(() => {
    // AC-10.9: once a refresh lands on a terminal outcome that improved the band (resolved, or a
    // fetch that produced setlists), tell the caller to refetch the job/playlist. Fired on the
    // state -> "succeeded" transition only, not on every poll of an already-terminal record.
    if (prevStateRef.current !== "succeeded" && state === "succeeded" && resolutionAfter === "resolved") {
      onBandResolved?.();
    }
    prevStateRef.current = state ?? null;
  }, [state, resolutionAfter, onBandResolved]);

  async function handleTrigger(): Promise<void> {
    setPickError(null);
    const output = await trigger.mutateAsync();
    // A throttle refusal (429) is written straight into the poll query's cache by the mutation's
    // own onSuccess — `refusedReason`/`retryAfterAt` only ever appear on that direct POST response
    // (`BandSetlistRefreshOutputMapper::refused()`), never on a later `GET` (`fromRecord()` never
    // sets them). Refetching over an accepted trigger, though, is what primes the poller's
    // `Retry-After`-driven interval (AC-10.4) — the POST response itself carries no such header.
    if (!output.refusedReason) {
      await poll.refetch();
    }
  }

  async function handleConfirmPick(): Promise<void> {
    if (!selectedMbid) {
      return;
    }
    setPickError(null);
    try {
      const output = await resolve.mutateAsync({ selectedMbid });
      setConfirming(false);
      setSelectedMbid(null);
      // D-277/AC-6.12: the identity write always lands, even when the completing fetch is itself
      // throttled (the pick endpoint still returns 202 with `refusedReason` set in that case) — so
      // the caller is told the band resolved either way.
      onBandResolved?.();
      if (!output.refusedReason) {
        await poll.refetch();
      }
    } catch (error) {
      const reason = pickRefusalReason(error);
      if (reason) {
        setPickError(reason);
        setConfirming(false);
        if (reason === "band_already_resolved") {
          // AC-10.10: a normal outcome — the band resolved out from under this pick. Refetch so the
          // screen shows the result rather than dead-ending on an error.
          onBandResolved?.();
        }
      } else {
        throw error;
      }
    }
  }

  const chosenCandidate: BandSearchCandidateOutput | undefined = (record?.candidates ?? []).find(
    (candidate) => candidate.mbid === selectedMbid,
  );

  const isAmbiguousOutcome =
    state === "succeeded" && resolutionAfter === "ambiguous" && (record?.candidates?.length ?? 0) > 0;

  // --- Cooldown, no in-flight refresh: disabled with the reason and return time (AC-10.3). -------
  // Checked before the generic terminal branch below: a cooldown can sit on top of ANY prior
  // outcome (never-refreshed has none; a completed refresh's own cooldownUntil still applies to a
  // NEW trigger). Never suppresses the ambiguous picker — D-270's pick is cooldown-exempt (D-277).
  if (!isAmbiguousOutcome && record?.cooldownUntil && cooldownCountdown) {
    return (
      <View testID={testID} style={{ gap: theme.space("space-2") }}>
        <Button
          testID={testID ? `${testID}-trigger` : undefined}
          label={`Try again around ${cooldownCountdown}`}
          onPress={() => undefined}
          disabled
        />
      </View>
    );
  }

  // --- Never refreshed, or the last one is terminal and no cooldown holds it: offer the action. ---
  if (!state || state === "succeeded" || state === "failed") {
    // Ambiguous outcome: show the candidate picker (AC-10.6), never another retry (AC-6.3).
    if (isAmbiguousOutcome) {
      return (
        <View testID={testID} style={{ gap: theme.space("space-3") }}>
          <Text
            style={{
              color: theme.colors["text-primary"],
              fontFamily: theme.resolveFontFamily("display", "semibold"),
              fontSize: theme.typeScale.sm.fontSize,
            }}
          >
            {bandName}: several bands answer to this name
          </Text>
          <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>
            Pick the one you saw live. This choice is shared with every Setlistify user of this band, and
            afterwards only support can change it.
          </Text>

          {(record?.candidates ?? []).map((candidate) => (
            <ListRow
              key={candidate.mbid}
              testID={testID ? `${testID}-candidate-${candidate.mbid}` : undefined}
              title={candidate.name ?? "Unknown"}
              subtitle={candidate.disambiguation ?? candidate.sortName ?? undefined}
              trailing={selectedMbid === candidate.mbid ? <Badge label="Selected" variant="info" /> : undefined}
              onPress={() => {
                setSelectedMbid(candidate.mbid ?? null);
                setConfirming(true);
                setPickError(null);
              }}
            />
          ))}

          {confirming && chosenCandidate ? (
            <View
              testID={testID ? `${testID}-confirm` : undefined}
              style={{ gap: theme.space("space-2"), padding: theme.space("space-3"), borderWidth: 1, borderColor: theme.colors["border-subtle"], borderRadius: theme.rad("md") }}
            >
              <Text style={{ color: theme.colors["text-primary"], fontSize: theme.typeScale.sm.fontSize }}>
                Set {bandName} to &quot;{chosenCandidate.name}
                {chosenCandidate.disambiguation ? ` — ${chosenCandidate.disambiguation}` : ""}&quot;?
              </Text>
              <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>
                This applies to this band for every user, and afterwards only support can change it.
              </Text>
              <View style={{ flexDirection: "row", gap: theme.space("space-2") }}>
                <Button
                  testID={testID ? `${testID}-confirm-cancel` : undefined}
                  label="Cancel"
                  variant="secondary"
                  onPress={() => {
                    setConfirming(false);
                    setSelectedMbid(null);
                  }}
                />
                <Button
                  testID={testID ? `${testID}-confirm-submit` : undefined}
                  label={resolve.isPending ? "Confirming…" : "Confirm"}
                  onPress={() => void handleConfirmPick()}
                  disabled={resolve.isPending}
                />
              </View>
            </View>
          ) : null}

          {pickError ? (
            <Text
              testID={testID ? `${testID}-pick-error` : undefined}
              style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}
            >
              {pickRefusalCopy(pickError)}
            </Text>
          ) : null}
        </View>
      );
    }

    // A resolved-and-fetched or otherwise terminal outcome that isn't ambiguous: nothing more to
    // pick — the caller's own "check again"/refetch surfaces the new result (AC-10.9 via
    // onBandResolved above). Fall through to a plain trigger control for a fresh attempt.
    if (refusedReason) {
      return (
        <View testID={testID} style={{ gap: theme.space("space-2") }}>
          <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>
            {refusalCopy(refusedReason, record?.retryAfterAt)}
          </Text>
        </View>
      );
    }

    return (
      <View testID={testID} style={{ gap: theme.space("space-2") }}>
        <Button
          testID={testID ? `${testID}-trigger` : undefined}
          label={trigger.isPending ? "Looking again…" : `Look again for ${bandName}`}
          onPress={() => void handleTrigger()}
          disabled={trigger.isPending}
        />
      </View>
    );
  }

  // --- queued/running: poll, honouring Retry-After (AC-10.4). --------------------------------------
  return (
    <View testID={testID} style={{ gap: theme.space("space-2") }}>
      <Badge testID={testID ? `${testID}-progress-badge` : undefined} label="Looking again…" variant="info" />
      <Text style={{ color: theme.colors["text-tertiary"], fontSize: theme.typeScale.xs.fontSize }}>
        Checking setlist.fm for {bandName} — this takes a few seconds.
      </Text>
    </View>
  );
}
