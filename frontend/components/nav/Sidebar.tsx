import React from "react";
import { Link, usePathname } from "expo-router";
import { Pressable, Text, View } from "react-native";

import { useTheme } from "@/theme";

import { NAV_DESTINATIONS } from "./destinations";

/**
 * Persistent desktop sidebar — `NavShell.dc.html` ("Desktop — persistent sidebar (≥1120px)"),
 * simplified to a single desktop breakpoint (D-39) rather than the canvas's extra collapsed-rail
 * and tablet-drawer variants — same two destinations, same route tree, just a width check away
 * from `BottomTabBar` rather than a separate implementation per intermediate width.
 */
export function Sidebar(): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const pathname = usePathname();

  return (
    <View
      testID="sidebar"
      style={{
        width: 220,
        borderRightWidth: 1,
        borderRightColor: colors["border-subtle"],
        backgroundColor: colors["surface-raised"],
        paddingVertical: theme.space("space-6"),
        paddingHorizontal: theme.space("space-4"),
        gap: theme.space("space-2"),
      }}
    >
      <Text
        style={{
          color: colors["text-primary"],
          fontFamily: theme.resolveFontFamily("display", "semibold"),
          fontSize: theme.typeScale.lg.fontSize,
          lineHeight: theme.typeScale.lg.lineHeight,
          marginBottom: theme.space("space-4"),
          paddingHorizontal: theme.space("space-2"),
        }}
      >
        Setlistify
      </Text>
      {NAV_DESTINATIONS.map((destination) => {
        const active = pathname.startsWith(destination.href);
        const Icon = destination.icon;
        return (
          <Link key={destination.href} href={destination.href} asChild>
            <Pressable
              accessibilityRole="link"
              accessibilityState={{ selected: active }}
              style={{
                flexDirection: "row",
                alignItems: "center",
                gap: theme.space("space-3"),
                minHeight: 44,
                borderRadius: theme.rad("md"),
                paddingHorizontal: theme.space("space-3"),
                backgroundColor: active ? colors["surface-sunken"] : "transparent",
              }}
            >
              <Icon
                size={20}
                color={active ? colors["accent-primary-strong"] : colors["text-secondary"]}
                strokeWidth={active ? 2.25 : 1.75}
              />
              <Text
                style={{
                  color: active ? colors["text-primary"] : colors["text-secondary"],
                  fontFamily: theme.resolveFontFamily("body", active ? "semibold" : "regular"),
                  fontSize: theme.typeScale.base.fontSize,
                }}
              >
                {destination.label}
              </Text>
            </Pressable>
          </Link>
        );
      })}
    </View>
  );
}
