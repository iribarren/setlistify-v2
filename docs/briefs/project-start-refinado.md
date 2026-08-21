# Start project

**Project**: Setlistify.

**Goal**: Create streaming playlists based on setlist.fm data for concerts users attend.

**Architecture**: Backend (API integration/DB) and Frontend (cross-platform web & mobile). Freemium commercial model.

**Security**: Strict API credential management; must separate local and production configurations.

## **Current Task: Planning & Environment Setup (DO NOT GENERATE CODE)**
*   Define the tech stack for a **cross-platform frontend (Web/Mobile)** and a **backend** handling API integrations.
*   Establish a **secure dev/prod environment setup** with strict API credential separation.
*   Generate implementation prompts for all MVP and Post-MVP features and save them to `@docs/prompts/`.
*   Propose prompts for frontend UI/UX design.
*   The prompts must be ordered meaningfully

## **Project Scope & Architecture**
*   **Monetization:** Freemium (free/paid tiers).
*   **MVP Features:**
    *   **Concert Tracker:** Add, list, and view upcoming/past concerts (band, date, venue, price).
    *   **Concert Pages:** Embed streaming player, add post-concert notes/reviews, share to socials.
    *   **Playlist Generator (Core):** Create exploratory prompts for this complex feature first. It requires two modes:
        *   **Fast Mode:** Fully automated using the latest setlist and auto-matching songs (must gracefully handle missing data/versions).
        *   **Normal Mode:** Interactive step-by-step process requiring user selection for both the specific setlist and preferred song versions.
*   **Post-MVP Features (Create exploratory prompts):**
    *   Fetch rich metadata (band photos, song IDs) from external APIs.
    *   Upload concert video snippets.
    *   Advanced social media sharing for plans and videos.