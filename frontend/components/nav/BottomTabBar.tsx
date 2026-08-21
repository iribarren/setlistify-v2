import React from "react";
import { Link, usePathname } from "expo-router";
import { Pressable, Text, View } from "react-native";

import { useTheme } from "@/theme";

import { NAV_DESTINATIONS } from "./destinations";

/**
 * Bottom tab bar — `NewComponents.dc.html` ("Bottom tab bar"). AC-9.1: the phone shell's
 * `Concerts`/`Account` navigation. AC-9.4: each destination is a full-width flex item at least 44px
 * tall, well clear of the touch-target minimum.
 */
export function BottomTabBar(): React.JSX.Element {
  const theme = useTheme();
  const { colors } = theme;
  const pathname = usePathname();

  return (
    <View
      testID="bottom-tab-bar"
      style={{
        flexDirection: "row",
        borderTopWidth: 1,
        borderTopColor: colors["border-subtle"],
        backgroundColor: colors["surface-raised"],
      }}
    >
      {NAV_DESTINATIONS.map((destination) => {
        const active = pathname.startsWith(destination.href);
        const Icon = destination.icon;
        return (
          <Link key={destination.href} href={destination.href} asChild>
            <Pressable
              accessibilityRole="tab"
              accessibilityState={{ selected: active }}
              accessibilityLabel={destination.label}
              style={{
                flex: 1,
                minHeight: 56,
                alignItems: "center",
                justifyContent: "center",
                gap: theme.space("space-1"),
              }}
            >
              <Icon
                size={22}
                color={active ? colors["accent-primary-strong"] : colors["text-tertiary"]}
                strokeWidth={active ? 2.25 : 1.75}
              />
              <Text
                style={{
                  color: active ? colors["accent-primary-strong"] : colors["text-tertiary"],
                  fontFamily: theme.resolveFontFamily("body", active ? "semibold" : "regular"),
                  fontSize: theme.typeScale.xs.fontSize,
                  lineHeight: theme.typeScale.xs.lineHeight,
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
