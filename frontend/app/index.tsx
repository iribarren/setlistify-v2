import React from "react";
import { ScrollView, Text, View } from "react-native";

import { Badge, Card } from "@/components";
import { DegradedState, ErrorState, LoadingState } from "@/components/state";
import { ApiError, useHealth, type HealthResult } from "@/lib/api";
import { useTheme } from "@/theme";

/**
 * US-9 — the health screen. Proves the whole chain end to end: token → component → query → typed
 * client → backend. This screen is explicitly a SCAFFOLD (AC-9.6): prompt 07 (concert tracker UI)
 * replaces it with the concert list as the app's real home route.
 */
export default function HealthScreen(): React.JSX.Element {
  const theme = useTheme();
  const { data, isLoading, isError, error, refetch } = useHealth();

  return (
    <ScrollView
      contentContainerStyle={{
        flexGrow: 1,
        backgroundColor: theme.colors["bg"],
        padding: theme.space("space-6"),
        justifyContent: "center",
      }}
      // AC-9.5: renders correctly at phone width and desktop width — a centered, capped-width
      // column rather than a full-bleed layout that would look wrong stretched to desktop.
      style={{ backgroundColor: theme.colors["bg"] }}
    >
      <View style={{ width: "100%", maxWidth: 480, alignSelf: "center", gap: theme.space("space-4") }}>
        <Text
          style={{
            color: theme.colors["text-primary"],
            fontFamily: theme.resolveFontFamily("display", "semibold"),
            fontSize: theme.typeScale["2xl"].fontSize,
            lineHeight: theme.typeScale["2xl"].lineHeight,
          }}
        >
          Setlistify
        </Text>

        {isLoading ? (
          <LoadingState title="Checking backend health" body="Talking to /api/health…" />
        ) : isError ? (
          <ErrorState
            title="Couldn't reach the backend"
            body={describeError(error)}
            action={{ label: "Try again", onPress: () => void refetch() }}
          />
        ) : data ? (
          <HealthSummary data={data} onRetry={() => void refetch()} />
        ) : null}
      </View>
    </ScrollView>
  );
}

function describeError(error: ApiError | null): string {
  if (!error) {
    return "Something went wrong.";
  }
  return error.detail ?? error.title;
}

function HealthSummary({
  data,
  onRetry,
}: {
  data: HealthResult;
  onRetry: () => void;
}): React.JSX.Element {
  const theme = useTheme();

  // AC-9.4: a 503 shows per-dependency detail — which one is failing, and that the others are
  // still healthy — rather than collapsing into a generic error.
  if (data.degraded) {
    const dependencies: [string, string][] = [
      ["database", data.database],
      ["redis", data.redis],
    ];
    const healthyCount = dependencies.filter(([, status]) => status === "ok").length;

    return (
      <View style={{ gap: theme.space("space-4") }}>
        <DegradedState
          testID="health-degraded"
          title="Backend degraded"
          body="At least one dependency is unhealthy. Your data is safe; some features may be slow or unavailable."
          progress={{ completed: healthyCount, total: dependencies.length }}
          action={{ label: "Try again", onPress: onRetry }}
        />
        <Card>
          {dependencies.map(([name, status]) => (
            <DependencyRow key={name} name={name} status={status} />
          ))}
        </Card>
      </View>
    );
  }

  return (
    <Card testID="health-ok">
      <Text
        style={{
          color: theme.colors["text-primary"],
          fontFamily: theme.resolveFontFamily("body", "semibold"),
          fontSize: theme.typeScale.lg.fontSize,
          lineHeight: theme.typeScale.lg.lineHeight,
          marginBottom: theme.space("space-3"),
        }}
      >
        All systems healthy
      </Text>
      <View style={{ gap: theme.space("space-2") }}>
        <DependencyRow name="status" status={data.status} />
        <DependencyRow name="database" status={data.database} />
        <DependencyRow name="redis" status={data.redis} />
      </View>
    </Card>
  );
}

function DependencyRow({ name, status }: { name: string; status: string }): React.JSX.Element {
  const theme = useTheme();
  const ok = status === "ok";

  return (
    <View
      style={{
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "space-between",
        minHeight: 32,
      }}
    >
      <Text
        style={{
          color: theme.colors["text-secondary"],
          fontFamily: theme.resolveFontFamily("mono", "regular"),
          fontSize: theme.typeScale.sm.fontSize,
        }}
      >
        {name}
      </Text>
      <Badge label={status} variant={ok ? "success" : "error"} />
    </View>
  );
}
