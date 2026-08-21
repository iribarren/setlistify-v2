# Implementation prompts

The ordered backlog. Each numbered file is a self-contained, ready-to-run prompt: open it, copy the
`/feature` (or `/spec`) line at the top, and the standard workflow in `CLAUDE.md` takes over —
specification, approval, branch, implementation with tests.

**Work them in order.** Start with the lowest-numbered prompt that is not yet done. Each file's
`Depends on:` line names what must exist first; nothing depends on a higher number.

## How to use one

```
/feature monorepo-and-environments
```

Then point the `project-manager-docs` agent at the prompt file when it asks for detail. The prompt's
**Scope**, **Out of scope** and **Acceptance criteria** sections are written to be pasted more or
less directly into the spec.

Prompts marked **spike** use `/spec` instead: they produce a design document and a recommendation,
**not** an implementation. They exist so the hard parts get thought through before they get built.

Prompts marked **design** use `/design`: they produce a visual design canvas, not code.

## Phases

| Phase | Prompts | What it gets you |
|---|---|---|
| Foundation | 00–04 | A running stack, a client that can talk to it, and users who can log in |
| Concert tracker | 05–07 | The core product loop: add a concert, see your concerts |
| Operability | 08 | A backoffice — from here on, everything is observable without a DB client |
| Integrations | 09–11 | setlist.fm, the streaming port, and runtime provider configuration |
| Playlist generator | 12–17 | The meat of the product, explored via spikes before it is built |
| Launch gate | 18 | A second provider — what lets more than 5 people use Setlistify |
| MVP completion | 19–21 | Playback, notes and reviews, sharing |
| Commercial groundwork | 22–23 | Usage limits, then an informed monetization decision |
| Post-MVP exploration | 24–26 | Rich metadata, video snippets, deeper social integration |

## The backlog

| # | Prompt | Kind |
|---|---|---|
| 00 | [monorepo-and-environments](00-monorepo-and-environments.md) | feature |
| 01 | [backend-skeleton](01-backend-skeleton.md) | feature |
| 02 | [design-system-foundations](02-design-system-foundations.md) | design |
| 03 | [frontend-skeleton](03-frontend-skeleton.md) | feature |
| 04 | [auth-and-accounts](04-auth-and-accounts.md) | feature |
| 05 | [concert-domain-api](05-concert-domain-api.md) | feature |
| 06 | [concert-screens-design](06-concert-screens-design.md) | design |
| 07 | [concert-tracker-ui](07-concert-tracker-ui.md) | feature |
| 08 | [backoffice-foundation](08-backoffice-foundation.md) | feature |
| 09 | [setlistfm-integration](09-setlistfm-integration.md) | feature |
| 10 | [streaming-port-and-account-linking](10-streaming-port-and-account-linking.md) | feature |
| 11 | [backoffice-provider-configuration](11-backoffice-provider-configuration.md) | feature |
| 12 | [spike-song-matching](12-spike-song-matching.md) | **spike** |
| 13 | [spike-playlist-pipeline](13-spike-playlist-pipeline.md) | **spike** |
| 14 | [playlist-fast-mode-backend](14-playlist-fast-mode-backend.md) | feature |
| 15 | [playlist-flow-design](15-playlist-flow-design.md) | design |
| 16 | [playlist-fast-mode-ui](16-playlist-fast-mode-ui.md) | feature |
| 17 | [playlist-normal-mode](17-playlist-normal-mode.md) | feature |
| 18 | [youtube-provider-adapter](18-youtube-provider-adapter.md) | feature |
| 19 | [concert-page-player-embed](19-concert-page-player-embed.md) | feature |
| 20 | [notes-and-reviews](20-notes-and-reviews.md) | feature |
| 21 | [social-sharing-basic](21-social-sharing-basic.md) | feature |
| 22 | [entitlement-and-quota-seam](22-entitlement-and-quota-seam.md) | feature |
| 23 | [spike-monetization-options](23-spike-monetization-options.md) | **spike** |
| 24 | [spike-rich-metadata](24-spike-rich-metadata.md) | **spike** |
| 25 | [spike-video-snippets](25-spike-video-snippets.md) | **spike** |
| 26 | [spike-advanced-social](26-spike-advanced-social.md) | **spike** |

## Before you start

Read `docs/architecture.md` and `docs/external-apis.md` once, properly. Several prompts will look
over-engineered without them — the setlist cache, the provider port and the runtime provider flags
all exist because of hard external constraints documented there, not because of taste.

Three things need doing outside the codebase, and two have long lead times:

- Register a setlist.fm API key, request the higher rate tier, and open the **commercial-use**
  conversation with them (partner@setlist.fm). Gates prompt 23.
- Create two Spotify apps (dev + prod) and allowlist your test accounts. Needed for prompt 10.
- Create a Google Cloud project with YouTube Data API access. Needed for prompt 18.
