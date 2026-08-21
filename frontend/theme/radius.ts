/**
 * Radius scale transcribed from `docs/design/canvas/SpacingElevation.dc.html` ("Radius scale").
 */
export type RadiusToken = "sm" | "md" | "lg" | "full";

export const radius: Record<RadiusToken, number> = {
  sm: 6, // inputs, badges, chips
  md: 12, // buttons, list rows
  lg: 18, // cards, sheets
  full: 999, // avatars, pills, FAB
};
