import React, { useEffect, useMemo, useState } from "react";
import { Text, View } from "react-native";

import { Button, TextInput } from "@/components";
import { DateField } from "../DateField";
import {
  emptyBandFormValue,
  hasFormErrors,
  maxDate,
  MIN_DATE,
  validateFormValues,
  type ConcertFormValues,
  type ViolationFieldErrors,
} from "@/lib/concerts";
import { useTheme } from "@/theme";

import { BandEntryRow, LineupCaption } from "./BandEntryRow";
import { DisclosureSection } from "./DisclosureSection";

export interface ConcertFormProps {
  initialValues: ConcertFormValues;
  onSubmit: (values: ConcertFormValues) => Promise<void>;
  submitLabel: string;
  submitting: boolean;
  /** AC-8.3: the last submit's parsed RFC 7807 violations, mapped onto this form's fields. */
  serverViolations?: ViolationFieldErrors | null;
  /** A non-validation failure (network, 5xx, offline) — shown once, at the top of the form. */
  formError?: string | null;
  /** AC-6.1/EditDelete.dc.html: a past concert's date is locked. */
  dateLocked?: boolean;
  onCancel?: () => void;
  /** AC-6.6: lets the screen know whether there are unsaved changes to confirm losing. */
  onDirtyChange?: (dirty: boolean) => void;
}

const emptyViolations: ViolationFieldErrors = { bands: {}, formErrors: [] };

/**
 * `AddConcert.dc.html` / `EditDelete.dc.html` — the ONE form both Add and Edit render (AC-6.1).
 * AC-3.9: Save is disabled only while a submission is in flight; client validation (D-36, advisory)
 * blocks a submit and shows inline errors rather than permanently disabling the button.
 */
export function ConcertForm({
  initialValues,
  onSubmit,
  submitLabel,
  submitting,
  serverViolations,
  formError,
  dateLocked,
  onCancel,
  onDirtyChange,
}: ConcertFormProps): React.JSX.Element {
  const theme = useTheme();
  const [values, setValues] = useState<ConcertFormValues>(initialValues);
  // D-36: recomputed live on every change (not just at submit time) so fixing a field clears its
  // client-side error immediately (AC-8.6) rather than showing a stale validation result.
  const clientErrors = useMemo(() => validateFormValues(values), [values]);
  const [attempted, setAttempted] = useState(false);
  const [serverErrors, setServerErrors] = useState<ViolationFieldErrors>(serverViolations ?? emptyViolations);

  // AC-8.3/AC-8.6: whenever the PARENT hands this form a new violations object (i.e. after a fresh
  // submit attempt), it replaces whatever per-field errors the user had already cleared locally.
  // Comparing against a previous-props value held in state (not a ref), and calling `setState`
  // directly in the render body when it differs, is the pattern React's docs recommend for
  // "adjusting state when a prop changes" — as opposed to a `useEffect` that fires after the fact.
  const [previousViolations, setPreviousViolations] = useState(serverViolations);
  if (previousViolations !== serverViolations) {
    setPreviousViolations(serverViolations);
    setServerErrors(serverViolations ?? emptyViolations);
  }

  useEffect(() => {
    onDirtyChange?.(JSON.stringify(values) !== JSON.stringify(initialValues));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [values]);

  function update<K extends keyof ConcertFormValues>(key: K, value: ConcertFormValues[K]): void {
    setValues((current) => ({ ...current, [key]: value }));
  }

  function clearServerField(key: keyof Omit<ViolationFieldErrors, "bands" | "formErrors">): void {
    setServerErrors((current) => ({ ...current, [key]: undefined }));
  }

  function clearServerBandError(index: number): void {
    setServerErrors((current) => {
      const bands = { ...current.bands };
      delete bands[index];
      return { ...current, bands };
    });
  }

  function updateBandName(index: number, name: string): void {
    setValues((current) => ({
      ...current,
      bands: current.bands.map((band, i) => (i === index ? { ...band, name } : band)),
    }));
    clearServerBandError(index);
  }

  function addBand(): void {
    setValues((current) => ({ ...current, bands: [...current.bands, emptyBandFormValue()] }));
  }

  function removeBand(index: number): void {
    setValues((current) => ({ ...current, bands: current.bands.filter((_, i) => i !== index) }));
  }

  function moveBand(index: number, direction: -1 | 1): void {
    setValues((current) => {
      const target = index + direction;
      if (target < 0 || target >= current.bands.length) {
        return current;
      }
      const bands = [...current.bands];
      const tmp = bands[index];
      bands[index] = bands[target];
      bands[target] = tmp;
      return { ...current, bands };
    });
  }

  async function handleSubmit(): Promise<void> {
    setAttempted(true);
    if (hasFormErrors(clientErrors)) {
      return; // AC-8.2/D-36: advisory — blocks THIS submit, never overrides a server response.
    }
    await onSubmit(values);
  }

  const showClientErrors = attempted;
  const dateError = serverErrors.date ?? (showClientErrors ? clientErrors.date : undefined);
  const bandsErrorSummary = showClientErrors ? clientErrors.bands : undefined;

  return (
    <View style={{ gap: theme.space("space-6") }}>
      {formError ? (
        <Text
          testID="concert-form-error"
          accessibilityRole="alert"
          style={{
            color: theme.colors["error-strong"],
            fontFamily: theme.resolveFontFamily("body", "semibold"),
            fontSize: theme.typeScale.sm.fontSize,
          }}
        >
          {formError}
        </Text>
      ) : null}

      {serverErrors.formErrors.length > 0 ? (
        <View testID="concert-form-summary-error" style={{ gap: theme.space("space-1") }}>
          {serverErrors.formErrors.map((message, index) => (
            <Text
              key={index}
              accessibilityRole="alert"
              style={{ color: theme.colors["error-strong"], fontFamily: theme.resolveFontFamily("body", "regular"), fontSize: theme.typeScale.sm.fontSize }}
            >
              {message}
            </Text>
          ))}
        </View>
      ) : null}

      <DateField
        testID="concert-form-date"
        label="Date"
        value={values.date}
        onChange={(date) => {
          update("date", date);
          clearServerField("date");
        }}
        minDate={MIN_DATE}
        maxDate={maxDate()}
        disabled={dateLocked}
        errorMessage={dateError}
      />
      {dateLocked ? (
        <Text
          style={{ color: theme.colors["text-tertiary"], fontFamily: theme.resolveFontFamily("body", "regular"), fontSize: theme.typeScale.xs.fontSize }}
        >
          This concert is marked past — the date is locked.
        </Text>
      ) : null}

      <View style={{ gap: theme.space("space-3") }}>
        <LineupCaption />
        {(bandsErrorSummary?.[0] || serverErrors.bands[0]) && values.bands.every((b) => !b.name.trim()) ? (
          <Text
            accessibilityRole="alert"
            style={{ color: theme.colors["error-strong"], fontFamily: theme.resolveFontFamily("body", "regular"), fontSize: theme.typeScale.sm.fontSize }}
          >
            {serverErrors.bands[0] ?? bandsErrorSummary?.[0]}
          </Text>
        ) : null}
        {values.bands.map((band, index) => (
          <BandEntryRow
            key={band.key}
            testID={`concert-form-band-${index}`}
            index={index}
            name={band.name}
            onChangeName={(name) => updateBandName(index, name)}
            onRemove={() => removeBand(index)}
            canRemove={values.bands.length > 1}
            onMoveUp={index > 0 ? () => moveBand(index, -1) : undefined}
            onMoveDown={index < values.bands.length - 1 ? () => moveBand(index, 1) : undefined}
            errorMessage={serverErrors.bands[index] ?? bandsErrorSummary?.[index]}
          />
        ))}
        <Button testID="add-band-button" label="Add band" variant="secondary" onPress={addBand} />
      </View>

      <DisclosureSection
        testID="venue-price-times-disclosure"
        title="Venue, price & times"
        defaultExpanded={Boolean(
          values.venueName || values.venueCity || values.priceAmount || values.doorsTime || values.startTime,
        )}
      >
        <TextInput
          testID="concert-form-venue-name"
          label="Venue"
          value={values.venueName}
          onChangeText={(text) => {
            update("venueName", text);
            clearServerField("venueName");
          }}
          placeholder="Search venues…"
          errorMessage={serverErrors.venueName}
        />
        <TextInput
          testID="concert-form-venue-city"
          label="City"
          value={values.venueCity}
          onChangeText={(text) => {
            update("venueCity", text);
            clearServerField("venueCity");
          }}
          errorMessage={serverErrors.venueCity}
        />
        <TextInput
          testID="concert-form-venue-country"
          label="Country"
          value={values.venueCountryCode}
          onChangeText={(text) => {
            update("venueCountryCode", text.toUpperCase());
            clearServerField("venueCountryCode");
          }}
          placeholder="GB"
          errorMessage={serverErrors.venueCountryCode}
        />
        <TextInput
          testID="concert-form-price-amount"
          label="Ticket price"
          value={values.priceAmount}
          onChangeText={(text) => {
            update("priceAmount", text);
            clearServerField("priceAmount");
          }}
          placeholder="12.50"
          keyboardType="decimal-pad"
          errorMessage={serverErrors.priceAmount}
        />
        <TextInput
          testID="concert-form-price-currency"
          label="Currency"
          value={values.priceCurrency}
          onChangeText={(text) => {
            update("priceCurrency", text.toUpperCase());
            clearServerField("priceCurrency");
          }}
          placeholder="GBP"
          errorMessage={serverErrors.priceCurrency}
        />
        <TextInput
          testID="concert-form-doors-time"
          label="Doors"
          value={values.doorsTime}
          onChangeText={(text) => {
            update("doorsTime", text);
            clearServerField("doorsTime");
          }}
          placeholder="19:00"
          errorMessage={serverErrors.doorsTime}
        />
        <TextInput
          testID="concert-form-start-time"
          label="Show starts"
          value={values.startTime}
          onChangeText={(text) => {
            update("startTime", text);
            clearServerField("startTime");
          }}
          placeholder="20:00"
          errorMessage={serverErrors.startTime}
        />
        <TextInput
          testID="concert-form-timezone"
          label="Timezone"
          value={values.timezone}
          onChangeText={(text) => {
            update("timezone", text);
            clearServerField("timezone");
          }}
          errorMessage={serverErrors.timezone}
        />
        <Text
          style={{ color: theme.colors["text-tertiary"], fontFamily: theme.resolveFontFamily("body", "regular"), fontSize: theme.typeScale.xs.fontSize }}
        >
          {values.timezone} — detected from this device
        </Text>
      </DisclosureSection>

      <View style={{ flexDirection: "row", gap: theme.space("space-3"), justifyContent: "flex-end" }}>
        {onCancel ? (
          <Button testID="concert-form-cancel" label="Cancel" variant="secondary" onPress={onCancel} disabled={submitting} />
        ) : null}
        <Button
          testID="concert-form-save"
          label={submitting ? "Saving…" : submitLabel}
          onPress={() => void handleSubmit()}
          disabled={submitting}
        />
      </View>
    </View>
  );
}
