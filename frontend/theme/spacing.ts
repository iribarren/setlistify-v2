/**
 * Spacing scale transcribed from `docs/design/canvas/SpacingElevation.dc.html` ("03 · Spacing,
 * radius & elevation") — 4px base unit. AC-2.5: no off-scale spacing number appears in a component;
 * every gap/padding/margin comes from this scale.
 */
export type SpaceToken =
  | "space-1"
  | "space-2"
  | "space-3"
  | "space-4"
  | "space-5"
  | "space-6"
  | "space-8"
  | "space-10"
  | "space-12"
  | "space-16"
  | "space-20"
  | "space-24";

export const spacing: Record<SpaceToken, number> = {
  "space-1": 4,
  "space-2": 8,
  "space-3": 12,
  "space-4": 16,
  "space-5": 20,
  "space-6": 24,
  "space-8": 32,
  "space-10": 40,
  "space-12": 48,
  "space-16": 64,
  "space-20": 80,
  "space-24": 96,
};
