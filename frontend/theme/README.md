# `frontend/theme/`

Typed design tokens transcribed from `docs/design/canvas/` (`Main.dc.html`, `Typography.dc.html`,
`SpacingElevation.dc.html`).

## The rule

**A token change starts on the canvas, not in code.** `docs/design/canvas/` is the source of truth.
If a value here needs to change, update the relevant `.dc.html` artboard first, then transcribe the
new value into `colors.ts` / `typography.ts` / `spacing.ts` / `radius.ts` / `elevation.ts`. Never
invent a token, and never introduce a raw hex value or an off-scale spacing number in a component —
if the scale doesn't have what you need, that is a canvas conversation, not a one-off in a
`StyleSheet`.

## What's here

| File | Canvas source | Contents |
|---|---|---|
| `colors.ts` | `Main.dc.html` | Light/dark palettes, keyed by the canvas's own token names |
| `typography.ts` | `Typography.dc.html` | Font families (Petrona/Manrope/Space Mono, D-13) + the size/line-height/weight scale |
| `spacing.ts` | `SpacingElevation.dc.html` | 4px-base spacing scale |
| `radius.ts` | `SpacingElevation.dc.html` | Corner radius scale |
| `elevation.ts` | `SpacingElevation.dc.html` | Single-layer shadow/elevation tokens |
| `ThemeProvider.tsx` | — | React context + `useTheme()`; the one place light/dark resolution happens |

## Usage

```tsx
import { useTheme } from "@/theme";

function Example() {
  const { colors, space, typeScale, resolveFontFamily } = useTheme();
  return (
    <View style={{ backgroundColor: colors["surface-raised"], padding: space("space-4") }}>
      <Text
        style={{
          color: colors["text-primary"],
          fontFamily: resolveFontFamily("body", "semibold"),
          fontSize: typeScale.lg.fontSize,
          lineHeight: typeScale.lg.lineHeight,
        }}
      >
        Hello
      </Text>
    </View>
  );
}
```

Never import `lightColors` / `darkColors` directly in a component — always go through `useTheme()`
so light/dark resolution stays in one place (AC-2.3). Importing the raw palettes directly is fine
only in tests that assert on token values (AC-10.4) or in `theme/` itself.

## Dark mode

Colors resolve from `useColorScheme()` (the OS setting) inside `ThemeProvider` — there is no in-app
toggle (AC-3.6). In dark mode, the canvas collapses each `-strong`/`-bright` pair (accent and
semantic colors) into a single AA-verified value; `colors.ts`'s header comment explains why both
keys still exist and resolve to that same value.

## Fonts

Petrona, Manrope and Space Mono are all SIL OFL — bundled as static weight files via `expo-font` and
the `@expo-google-fonts/*` packages (D-13), not linked from Google Fonts (that `<link>` in the
canvas HTML is web-only). Only the weights the type scale actually uses are bundled. `app/_layout.tsx`
loads them with `useFonts(fontsToLoad)` before rendering the app, so there is no unstyled-text flash
on native; on web, `resolveFontFamily()` appends the canvas's CSS fallback stack so text stays
legible during the brief pre-load window.
