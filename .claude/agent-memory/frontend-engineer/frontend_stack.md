---
name: frontend_stack
description: Setlistify frontend stack, structure, and conventions (Expo/Expo Router/TypeScript strict, theme system, generated API client)
metadata:
  type: project
---

`frontend/` is an Expo SDK 57 + Expo Router + TypeScript-strict app (React 19.2, React Native
0.86), shipped in the `feature/frontend-skeleton` branch (spec:
`docs/specs/2026-08-21-frontend-skeleton.md`). One codebase for web/iOS/Android, no platform-forked
files.

**Why:** CLAUDE.md's Structure table already named this stack; the skeleton spec (D-10..D-17) made
the concrete implementation choices. Full rationale lives in the spec and in `frontend/README.md` /
`frontend/theme/README.md` / `frontend/components/state/README.md` — read those before re-deriving
conventions.

**How to apply:** Any new screen/component work should follow what's already there rather than
reinvent:
- Design tokens: `frontend/theme/` (colors/typography/spacing/radius/elevation), consumed only via
  `useTheme()`. No raw hex/off-scale spacing in component files (an ESLint rule enforces this).
- Base components: `frontend/components/` (Button, TextInput, Card, ListRow, Badge, Avatar) and
  `frontend/components/state/` (LoadingState, EmptyState, DegradedState, ErrorState — the ONLY
  sanctioned way to render those four conditions anywhere in the app).
- API access: only through `frontend/lib/api/` (`apiClient` + `unwrap()` + query hooks like
  `useHealth`). Never call `fetch` directly from a component.
- `frontend/api/` is GENERATED (`npm run generate:api`, openapi-typescript) and committed — never
  hand-edited. Regenerate after any backend API change in the same branch.
- Fonts are bundled via `expo-font` + `@expo-google-fonts/{manrope,petrona,space-mono}` (static TTF
  weight files), not the web-only Google Fonts `<link>`.

See [[frontend_tooling_gotchas]] for the non-obvious package-version/tooling issues hit while
standing this up — worth checking before debugging a similar symptom from scratch.
