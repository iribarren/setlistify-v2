# 02 — Design system foundations

**Command:** `/design` · **Agent:** design canvas · **Depends on:** —

## Goal
A visual foundation for Setlistify — palette, typography, spacing, and the core component
inventory — decided deliberately and once, so that every screen built afterwards is consistent by
construction rather than by retrofit.

## Context
No UI exists yet. This prompt runs before the frontend skeleton (03) so the Expo app can be built
with real design tokens from its first commit instead of placeholder styling that never gets
replaced.

The product's emotional register is worth getting right: Setlistify is about live music and personal
memory — concerts you went to, what the band actually played, what it was like. It should feel closer
to a well-made ticket stub or a gig diary than to a data dashboard.

## Scope
Produce a design canvas covering:
- **Palette**: primary, secondary, surface, and semantic colours (success, warning, error, info),
  fully specified for **both light and dark themes**. Dark mode is not an afterthought — a concert
  app gets used in a dark venue.
- **Typography**: family choices with real fallback stacks, a type scale, and the weights in use.
- **Spacing and radius scale**, plus elevation/shadow treatment.
- **Component inventory**, drawn at least once each: buttons (primary/secondary/destructive/disabled),
  text and date inputs, cards, list rows, tabs, modals/sheets, toasts, badges, avatars, and — this
  product needs them constantly — **empty, loading, degraded and error states**.
- **The concert card**, the product's signature component: band(s), date, venue, and a status
  indicating whether a playlist exists.
- **Iconography** direction and source.
- **Accessibility**: contrast ratios verified against WCAG AA, minimum touch targets, focus states.

## Out of scope
- Full screen layouts — prompt 06 (concert screens) and 15 (playlist flow).
- Any code. This prompt produces a design canvas; prompt 03 translates it into tokens.
- Marketing and landing pages.

## Acceptance criteria
- [ ] Light and dark palettes are both complete, and every colour pairing used for text meets WCAG AA.
- [ ] The type scale is defined with fallbacks that work on iOS, Android and web.
- [ ] Every component in the inventory is drawn in its default, hover/pressed, disabled and error
      states where applicable.
- [ ] Empty, loading and degraded states are designed, not left as "TBD" — they are the normal case
      in this product, not the exception.
- [ ] Tokens are named and structured so prompt 03 can transcribe them directly into code.
- [ ] The design works at phone width and at desktop width.

## Risks & open questions
- Fonts must be licensed for app embedding, not just web use. Check before committing to a family.
- react-native-web narrows what styling works across all three platforms — avoid shadows and effects
  that only render on one.
- Degraded states deserve real design attention here. "This band has no setlists on setlist.fm" and
  "we matched 14 of 19 songs" are frequent, expected outcomes; if they look like errors, the product
  will feel broken when it is working correctly.
