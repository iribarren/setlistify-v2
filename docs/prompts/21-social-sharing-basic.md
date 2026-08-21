# 21 — Social sharing

**Command:** `/feature social-sharing-basic` · **Agent:** `frontend-engineer` + `backend-engineer` · **Depends on:** 19, 20

## Goal
A user can share a concert — the lineup, the setlist, the playlist, optionally their review — as a
link that looks good when pasted anywhere, and through the native share sheet on mobile.

## Context
Sharing is how anyone else discovers Setlistify, so this is the first feature with a growth
dimension. It is also the first that publishes user data outside the app, which makes the privacy
model the important part rather than an afterthought.

**Two rules that must not be quietly relaxed.** Reviews are private by default (prompt 20) — sharing
must be an explicit, per-share act, never implied by having written one. And a shared link must not
expose anything the user did not deliberately include.

## Scope
- A `ShareLink` entity: an unguessable token, the concert, what is included (lineup and setlist always;
  playlist and review each opt-in), created-at, optional expiry, and a revoked flag.
- Sharing is **explicit and granular**: the user chooses what a given link includes, per link.
- Revocation, and a list of a user's active share links so sharing is not a one-way door.
- A public, server-rendered share page — deliberately **not** the Expo SPA, because it must produce
  correct Open Graph and Twitter Card metadata for link previews, which a client-rendered SPA cannot.
  Serve it from Symfony.
- Generated OG images: lineup, date, venue, and Setlistify branding, cached and regenerated on change.
- The share page shows only what the link includes, is `noindex` by default, and requires no account
  to view.
- Native share sheet integration on iOS and Android; clipboard copy plus a Web Share API path on web.
- Rate limiting on share-link creation, and abuse-resistant token generation.
- Tests: link creation with each inclusion combination, revocation, expiry, OG metadata rendering,
  and — importantly — that a link never exposes an excluded field.

## Out of scope
- Posting directly to social platforms' APIs — prompt 26 explores that; the native share sheet covers
  the realistic cases for now.
- Video — prompt 25.
- Public user profiles or any social graph.

## Acceptance criteria
- [ ] A user creates a share link, chooses what it includes, and sees exactly that when opening it
      logged out.
- [ ] **An excluded review or playlist is not retrievable through the shared link by any means** —
      including the underlying API. Covered by test.
- [ ] Revoking a link makes it stop working immediately.
- [ ] The share page renders correct OG and Twitter Card metadata; previews render properly in at
      least two real destinations.
- [ ] The generated OG image shows lineup, date and venue legibly at typical preview sizes.
- [ ] The native share sheet works on iOS and Android; web offers Web Share API with clipboard
      fallback.
- [ ] Share tokens are unguessable and enumeration-resistant.
- [ ] The share page is `noindex` unless the user explicitly opts into discoverability.
- [ ] Share-link creation is rate-limited.

## Risks & open questions
- The share page is server-rendered Symfony while the app is an Expo SPA — two rendering paths for
  concert data, which is duplication with a real justification (link previews). Keep the shared page
  minimal so the duplication stays small.
- Decide whether shared pages should be indexable at all. Discoverability helps growth; it also means
  someone's gig history is searchable. Default to `noindex` and make opting in explicit.
- OG image generation needs a rendering approach that works in the PHP container — check what is
  available before committing, and cache aggressively.
- Consider whether a shared playlist link should carry the provider embed. That interacts with
  prompt 11's `playbackMode` and with the SDA question in `docs/external-apis.md` — reuse the same
  flag rather than introducing a second rule.
