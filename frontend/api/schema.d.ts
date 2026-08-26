/**
 * GENERATED FILE — do not edit by hand (AC-6.3, R-1).
 *
 * Produced by: npm run generate:api
 *   Source: backend/openapi.json (docker compose exec backend bin/console api:openapi:export --output=openapi.json)
 *
 * If this file looks wrong, the fix is in the backend's API Platform resource metadata, or in
 * frontend/scripts/generate-api.mjs's generator config — never a hand-edit here. See
 * docs/specs/2026-08-21-frontend-skeleton.md, R-1.
 */
export interface paths {
    "/api/band-searches": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves a BandSearch resource.
         * @description Retrieves a BandSearch resource.
         */
        get: operations["api_band-searches_get"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/bands/{bandId}/setlists": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves a BandSetlists resource.
         * @description Retrieves a BandSetlists resource.
         */
        get: operations["api_bands_bandIdsetlists_get"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/concerts": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves the collection of Concert resources.
         * @description Retrieves the collection of Concert resources.
         */
        get: operations["api_concerts_get_collection"];
        put?: never;
        /**
         * Creates a Concert resource.
         * @description Creates a Concert resource.
         */
        post: operations["api_concerts_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/concerts/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves a Concert resource.
         * @description Retrieves a Concert resource.
         */
        get: operations["api_concerts_id_get"];
        put?: never;
        post?: never;
        /**
         * Removes the Concert resource.
         * @description Removes the Concert resource.
         */
        delete: operations["api_concerts_id_delete"];
        options?: never;
        head?: never;
        /**
         * Updates the Concert resource.
         * @description Updates the Concert resource.
         */
        patch: operations["api_concerts_id_patch"];
        trace?: never;
    };
    "/api/concerts/{concertId}/review": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves a ConcertReview resource.
         * @description Retrieves a ConcertReview resource.
         */
        get: operations["api_concerts_concertIdreview_get"];
        /**
         * Replaces the ConcertReview resource.
         * @description Replaces the ConcertReview resource.
         */
        put: operations["api_concerts_concertIdreview_put"];
        post?: never;
        /**
         * Removes the ConcertReview resource.
         * @description Removes the ConcertReview resource.
         */
        delete: operations["api_concerts_concertIdreview_delete"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/email-verification/confirm": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a EmailVerificationConfirm resource.
         * @description Creates a EmailVerificationConfirm resource.
         */
        post: operations["api_email-verificationconfirm_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/email-verification/resend": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a EmailVerificationResend resource.
         * @description Creates a EmailVerificationResend resource.
         */
        post: operations["api_email-verificationresend_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/health": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves a Health resource.
         * @description Retrieves a Health resource.
         */
        get: operations["api_health_get"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/login": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a Login resource.
         * @description Creates a Login resource.
         */
        post: operations["api_login_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/logout": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a Logout resource.
         * @description Creates a Logout resource.
         */
        post: operations["api_logout_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/me": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves a Me resource.
         * @description Retrieves a Me resource.
         */
        get: operations["api_me_get"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/password-reset/confirm": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a PasswordResetConfirm resource.
         * @description Creates a PasswordResetConfirm resource.
         */
        post: operations["api_password-resetconfirm_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/password-reset/request": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a PasswordResetRequest resource.
         * @description Creates a PasswordResetRequest resource.
         */
        post: operations["api_password-resetrequest_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/playlists": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves the collection of Playlist resources.
         * @description Retrieves the collection of Playlist resources.
         */
        get: operations["api_playlists_get_collection"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/playlists/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves a Playlist resource.
         * @description Retrieves a Playlist resource.
         */
        get: operations["api_playlists_id_get"];
        put?: never;
        post?: never;
        /**
         * Removes the Playlist resource.
         * @description Removes the Playlist resource.
         */
        delete: operations["api_playlists_id_delete"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/playlist-generation-jobs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves the collection of PlaylistGenerationJob resources.
         * @description Retrieves the collection of PlaylistGenerationJob resources.
         */
        get: operations["api_playlist-generation-jobs_get_collection"];
        put?: never;
        /**
         * Creates a PlaylistGenerationJob resource.
         * @description Creates a PlaylistGenerationJob resource.
         */
        post: operations["api_playlist-generation-jobs_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/playlist-generation-jobs/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves a PlaylistGenerationJob resource.
         * @description Retrieves a PlaylistGenerationJob resource.
         */
        get: operations["api_playlist-generation-jobs_id_get"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/playlist-generation-jobs/{id}/cancel": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a PlaylistGenerationJob resource.
         * @description Creates a PlaylistGenerationJob resource.
         */
        post: operations["api_playlist-generation-jobs_idcancel_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/playlist-generation-jobs/{id}/candidate-setlists": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves a PlaylistGenerationJob resource.
         * @description Retrieves a PlaylistGenerationJob resource.
         */
        get: operations["api_playlist-generation-jobs_idcandidate-setlists_get"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/playlist-generation-jobs/{id}/create-anyway": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a PlaylistGenerationJob resource.
         * @description Creates a PlaylistGenerationJob resource.
         */
        post: operations["api_playlist-generation-jobs_idcreate-anyway_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/playlist-generation-jobs/{id}/pending-choices": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves a PlaylistGenerationJob resource.
         * @description Retrieves a PlaylistGenerationJob resource.
         */
        get: operations["api_playlist-generation-jobs_idpending-choices_get"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/playlist-generation-jobs/{id}/retry": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a PlaylistGenerationJob resource.
         * @description Creates a PlaylistGenerationJob resource.
         */
        post: operations["api_playlist-generation-jobs_idretry_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/playlist-generation-jobs/{id}/setlist-choice": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a PlaylistGenerationJob resource.
         * @description Creates a PlaylistGenerationJob resource.
         */
        post: operations["api_playlist-generation-jobs_idsetlist-choice_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/playlist-generation-jobs/{id}/version-choices": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a PlaylistGenerationJob resource.
         * @description Creates a PlaylistGenerationJob resource.
         */
        post: operations["api_playlist-generation-jobs_idversion-choices_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/config/providers": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves the collection of ProviderConfig resources.
         * @description Retrieves the collection of ProviderConfig resources.
         */
        get: operations["api_configproviders_get_collection"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/token/refresh": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a Refresh resource.
         * @description Creates a Refresh resource.
         */
        post: operations["api_tokenrefresh_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/setlists/{setlistfmId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves a Setlist resource.
         * @description Retrieves a Setlist resource.
         */
        get: operations["api_setlists_setlistfmId_get"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/streaming/accounts": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves the collection of StreamingAccount resources.
         * @description Retrieves the collection of StreamingAccount resources.
         */
        get: operations["api_streamingaccounts_get_collection"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/streaming/accounts/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /**
         * Removes the StreamingAccount resource.
         * @description Removes the StreamingAccount resource.
         */
        delete: operations["api_streamingaccounts_id_delete"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/streaming/link": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a StreamingLink resource.
         * @description Creates a StreamingLink resource.
         */
        post: operations["api_streaminglink_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/streaming/link-results/{ref}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieves a StreamingLinkResult resource.
         * @description Retrieves a StreamingLinkResult resource.
         */
        get: operations["api_streaminglink-results_ref_get"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/users": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Creates a User resource.
         * @description Creates a User resource.
         */
        post: operations["api_users_post"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
}
export type webhooks = Record<string, never>;
export interface components {
    schemas: {
        "BandOutput.jsonld": {
            id?: number;
            name?: string;
        };
        /** @description Search setlist.fm's artist index by free-text name (US-1). Cached (AC-1.7) — searching the same string twice makes one outbound call. */
        "BandSearch.BandSearchOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            candidates?: components["schemas"]["BandSearchCandidateOutput.jsonld"][];
            freshness?: components["schemas"]["FreshnessEnvelope.jsonld"];
        };
        "BandSearchCandidateOutput.jsonld": {
            mbid?: string;
            name?: string;
            sortName?: string | null;
            disambiguation?: string | null;
        };
        /** @description A band's past setlists, newest first (US-3). */
        "BandSetlists.BandSetlistsOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            /** @description One of 'resolved'|'ambiguous'|'no_presence'|'unresolved' (mirrors `Band::RESOLUTION_*`). */
            state?: string;
            candidates?: components["schemas"]["BandSearchCandidateOutput.jsonld"][];
            setlists?: components["schemas"]["SetlistSummaryOutput.jsonld"][];
            totalItems?: number;
            page?: number;
            itemsPerPage?: number;
            freshness?: components["schemas"]["FreshnessEnvelope.jsonld"];
        };
        "CandidateSetlistBandOutput.jsonld": {
            bandId?: number;
            bandName?: string;
            billingOrder?: number;
            recommendedSetlistfmId?: string | null;
            recommendedReason?: string | null;
            /** @description Non-null only when this band has nothing (D-183) — an explanatory row, not a question. */
            noSetlistCause?: string | null;
            candidates?: components["schemas"]["CandidateSetlistOutput.jsonld"][];
        };
        "CandidateSetlistOutput.jsonld": {
            setlistfmId?: string;
            eventDate?: string;
            venueName?: string | null;
            cityName?: string | null;
            countryCode?: string | null;
            tourName?: string | null;
            songCount?: number;
            isSameNight?: boolean;
            url?: string | null;
        };
        /** @description A concert the authenticated user attended or is planning to attend — bands, date, venue, and what it cost (US-1 through US-7). */
        "Concert.ConcertInput": {
            /** @description ISO-8601 calendar date, [1900-01-01, now+5y] (D-31, AC-9.2). */
            date: string | null;
            /** @description IANA identifier, e.g. `Europe/Madrid`. A fixed offset like `+02:00` is rejected (D-24, AC-9.3). */
            timezone: string | null;
            lineup?: components["schemas"]["LineupEntryInput"][];
            venue?: components["schemas"]["VenueData"] | null;
            ticketPrice?: components["schemas"]["MoneyData"] | null;
            /** @description Local wall-clock `HH:MM` in `$timezone` (AC-2.5). */
            doorsTime?: string | null;
            startTime?: string | null;
        };
        /** @description A concert the authenticated user attended or is planning to attend — bands, date, venue, and what it cost (US-1 through US-7). */
        "Concert.ConcertOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            id?: number;
            date?: string;
            timezone?: string;
            status?: string;
            lineup?: components["schemas"]["LineupEntryOutput.jsonld"][];
            venue?: components["schemas"]["VenueData.jsonld"];
            ticketPrice?: components["schemas"]["MoneyData.jsonld"] | null;
            doorsTime?: string | null;
            startTime?: string | null;
            reviewSummary?: components["schemas"]["ConcertReviewSummaryOutput.jsonld"] | null;
            /** Format: date-time */
            createdAt?: string;
            /** Format: date-time */
            updatedAt?: string;
        };
        /** @description A concert the authenticated user attended or is planning to attend — bands, date, venue, and what it cost (US-1 through US-7). */
        "Concert.ConcertPatchInput.jsonMergePatch": {
            date?: string | null;
            timezone?: string | null;
            lineup?: components["schemas"]["LineupEntryInput"][];
            venue?: components["schemas"]["VenueData"] | null;
            ticketPrice?: components["schemas"]["MoneyData"] | null;
            doorsTime?: string | null;
            startTime?: string | null;
        };
        /** @description One user's write-up of one concert — rating, notes and an optional highlight (US-1 through US-5). */
        "ConcertReview.ConcertReviewInput": {
            /** @description 1-5 inclusive (D-230). Nullable — a review may be notes-only (D-231). */
            rating?: number | null;
            /** @description Plain text, no rendering contract (D-237), ≤ 4000 graphemes so a family emoji costs 1, not 7 (D-236). */
            notes?: string | null;
            /** @description Must belong to a `Setlist`/`Song` of a band in this concert's lineup — checked by the processor (D-233). */
            highlightSongId?: number | null;
            /** @description The always-populated snapshot; the only thing ever rendered (D-232). */
            highlightTitle?: string | null;
        };
        /** @description One user's write-up of one concert — rating, notes and an optional highlight (US-1 through US-5). */
        "ConcertReview.ConcertReviewOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            rating?: number | null;
            notes?: string | null;
            highlightSongId?: number | null;
            highlightTitle?: string | null;
            /** Format: date-time */
            createdAt?: string;
            /** Format: date-time */
            updatedAt?: string;
        };
        "ConcertReviewSummaryOutput.jsonld": {
            rating?: number | null;
            highlightTitle?: string | null;
            /** Format: date-time */
            updatedAt?: string;
        };
        /** @description Unprocessable entity */
        ConstraintViolation: {
            /** @default 422 */
            status: number;
            violations?: {
                /** @description The property path of the violation */
                propertyPath: string;
                /** @description The message associated with the violation */
                message: string;
                /** @description The code of the violation */
                code?: string;
                /** @description An extra hint to understand the violation */
                hint?: string;
                /** @description The serialized payload of the violation */
                payload?: {
                    [key: string]: unknown;
                };
            }[];
            readonly detail?: string;
            readonly type?: string;
            readonly title?: string | null;
            readonly instance?: string | null;
        };
        /**
         * @description `POST /api/email-verification/confirm` (AC-7.2). A used, expired or unknown token all give one
         *     indistinguishable 400. Implemented as `POST` only (a body-carrying, non-idempotent action);
         *     `docs/specs/2026-08-21-auth-and-accounts.md`'s `GET|POST` was written for a click-through email
         *     link, but a `GET` that mutates state is both a CSRF surface and awkward with a JSON body — the
         *     frontend's verification screen extracts the token from the deep link and POSTs it instead
         *     (recorded as a deviation in the implementation report).
         */
        "EmailVerificationConfirm.EmailVerificationConfirmInput": {
            /** @default  */
            token: string;
        };
        /**
         * @description `POST /api/email-verification/resend` (AC-7.3). Requires a bearer JWT (any authenticated user,
         *     verified or not — this is the endpoint an unverified user calls). Always 202 and never reveals
         *     whether the account was already verified.
         */
        "EmailVerificationResend.GenericAck.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            message?: string;
        };
        /** @description A representation of common errors. */
        Error: {
            /** @description A short, human-readable summary of the problem. */
            readonly title?: string | null;
            /** @description A human-readable explanation specific to this occurrence of the problem. */
            readonly detail?: string | null;
            /**
             * @default 400
             * @example 404
             */
            status: number | null;
            /** @description A URI reference that identifies the specific occurrence of the problem. It may or may not yield further information if dereferenced. */
            readonly instance?: string | null;
            /** @description A URI reference that identifies the problem type */
            readonly type?: string;
        };
        "FreshnessEnvelope.jsonld": {
            source?: string;
            /** Format: date-time */
            fetchedAt?: string | null;
            stale?: boolean;
            reason?: string | null;
            /**
             * Format: date-time
             * @description AC-8.4: when the daily budget resets, so the client can say "tomorrow at …".
             */
            budgetResetAt?: string | null;
        };
        /** @description Reports whether the application and its dependencies (database, Redis) are actually usable — not just that the container is up. */
        "Health.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            /** @description Overall status: `ok` when every dependency is healthy, `error` otherwise. */
            status?: string;
            /** @description `ok` or `error` — a real round-trip result, never a configuration read. */
            database?: string;
            /** @description `ok` or `error` — a real round-trip result, never a configuration read. */
            redis?: string;
        };
        HydraCollectionBaseSchema: components["schemas"]["HydraCollectionBaseSchemaNoPagination"] & {
            /**
             * @example {
             *       "@id": "string",
             *       "@type": "string",
             *       "first": "string",
             *       "last": "string",
             *       "previous": "string",
             *       "next": "string"
             *     }
             */
            view?: {
                /** Format: iri-reference */
                "@id"?: string;
                "@type"?: string;
                /** Format: iri-reference */
                first?: string | null;
                /** Format: iri-reference */
                last?: string | null;
                /** Format: iri-reference */
                previous?: string | null;
                /** Format: iri-reference */
                next?: string | null;
            };
        };
        HydraCollectionBaseSchemaNoPagination: {
            totalItems?: number;
            search?: {
                "@type"?: string;
                template?: string;
                variableRepresentation?: string;
                mapping?: {
                    "@type"?: string;
                    variable?: string;
                    property?: string | null;
                    required?: boolean;
                }[];
            };
        };
        HydraItemBaseSchema: {
            "@context"?: string | ({
                "@vocab": string;
                /** @enum {string} */
                hydra: "http://www.w3.org/ns/hydra/core#";
            } & {
                [key: string]: unknown;
            });
            "@id": string;
            "@type": string;
        };
        LineupEntryInput: {
            /** @description 1–120 characters after trimming (AC-9.4). Skipped when null (a `bandId` entry). */
            name?: string | null;
            bandId?: number | null;
        };
        "LineupEntryOutput.jsonld": {
            band?: components["schemas"]["BandOutput.jsonld"];
            billingOrder?: number;
        };
        /**
         * @description `POST /api/login` (US-2). Wrong password, unknown email, unverified account (when
         *     `AUTH_REQUIRE_VERIFIED_EMAIL` is on) and a disabled account all fail identically — a generic 401
         *     with no distinguishing detail (AC-2.4, US-9) — enforced entirely in {@see LoginProcessor}.
         */
        "Login.LoginInput": {
            /**
             * Format: email
             * @default
             */
            email: string;
            /** @default  */
            password: string;
        };
        /**
         * @description `POST /api/login` (US-2). Wrong password, unknown email, unverified account (when
         *     `AUTH_REQUIRE_VERIFIED_EMAIL` is on) and a disabled account all fail identically — a generic 401
         *     with no distinguishing detail (AC-2.4, US-9) — enforced entirely in {@see LoginProcessor}.
         */
        "Login.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            accessToken?: string;
            tokenType?: string;
            /** @description Seconds until the access token expires (AC-2.2). */
            expiresIn?: number;
            /**
             * @description Present only for `X-Client-Platform: native` requests (AC-4.6, D-18) — web clients get
             *     the refresh token exclusively via the httpOnly cookie and this is always `null`.
             */
            refreshToken?: string | null;
        };
        /**
         * @description `POST /api/logout` (US-5). Revokes the presented refresh token's entire family. Always 204, even
         *     when the presented token is missing or already invalid (AC-5.4) — logging out must never fail
         *     visibly, since the whole point is to make a device's session unusable.
         */
        "Logout.LogoutInput": {
            refreshToken?: string | null;
        };
        /** @description The authenticated user's own identity. */
        "Me.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            id?: number;
            email?: string;
            emailVerified?: boolean;
            roles?: string[];
            /** Format: date-time */
            createdAt?: string;
        };
        MoneyData: {
            amount?: number | null;
            currency?: string | null;
        };
        "MoneyData.jsonld": {
            amount?: number | null;
            currency?: string | null;
        };
        /**
         * @description `POST /api/password-reset/confirm` (AC-6.3–AC-6.6). An expired, unknown or already-used token
         *     all produce one indistinguishable 400 (AC-6.5). On success: the token is consumed, every other
         *     outstanding reset token for the user is invalidated, and every refresh-token family for the user
         *     is revoked — a password reset logs out every device (AC-6.4).
         */
        "PasswordResetConfirm.PasswordResetConfirmInput": {
            /** @default  */
            token: string;
            /**
             * @description Same policy as registration (AC-1.4, AC-6.3).
             * @default
             */
            password: string;
        };
        /**
         * @description `POST /api/password-reset/request` (US-6). Always 202 with the same body whether or not the
         *     address exists (AC-6.1, US-9) — {@see PasswordResetRequestProcessor} never lets a caller
         *     distinguish the two.
         */
        "PasswordResetRequest.GenericAck.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            message?: string;
        };
        /**
         * @description `POST /api/password-reset/request` (US-6). Always 202 with the same body whether or not the
         *     address exists (AC-6.1, US-9) — {@see PasswordResetRequestProcessor} never lets a caller
         *     distinguish the two.
         */
        "PasswordResetRequest.PasswordResetRequestInput": {
            /**
             * Format: email
             * @default
             */
            email: string;
        };
        "PendingChoiceAutoResolvedOutput.jsonld": {
            sourcePosition?: number;
            bandName?: string;
            sourceTitle?: string;
            providerTrackId?: string | null;
            /** @enum {string} */
            label?: "top_pick" | "only_match" | "alternative" | "your_previous_choice";
            /** @enum {string|null} */
            reasonCode?: "COVER_OF" | "LIVE_VERSION_ONLY" | "LOW_CONFIDENCE_MATCH" | "TAPE_NOT_PERFORMED" | "PERFORMANCE_ARTIFACT" | "TRACK_NOT_IN_CATALOG" | "TRACK_VANISHED" | "NOT_AVAILABLE_IN_REGION" | "NO_SETLIST_FOR_BAND" | "SETLIST_MAY_BE_STALE" | "SELECTED_FROM" | "BANDS_OMITTED_FOR_LENGTH" | "SETLIST_TRUNCATED" | "RESUMED_MID_INSERTION" | "FALLBACK_LONGEST_SETLIST" | "USED_YOUR_PREVIOUS_CHOICE" | "USER_DECLINED" | "SETLIST_CORRECTED_SINCE_SELECTION" | "RESCORED_AFTER_ALGORITHM_UPDATE" | "SELECTED_SETLIST_UNAVAILABLE" | null;
            reasonParams?: {
                [key: string]: string | null;
            } | null;
        };
        "PendingChoiceCandidateOutput.jsonld": {
            providerTrackId?: string;
            title?: string | null;
            artistName?: string | null;
            albumName?: string | null;
            releaseYear?: number | null;
            durationMs?: number | null;
            /** @enum {string} */
            label?: "top_pick" | "only_match" | "alternative" | "your_previous_choice";
        };
        "PendingChoiceDecisionOutput.jsonld": {
            sourcePosition?: number;
            segmentIndex?: number | null;
            bandName?: string;
            sourceTitle?: string;
            /** @enum {string|null} */
            reasonCode?: "COVER_OF" | "LIVE_VERSION_ONLY" | "LOW_CONFIDENCE_MATCH" | "TAPE_NOT_PERFORMED" | "PERFORMANCE_ARTIFACT" | "TRACK_NOT_IN_CATALOG" | "TRACK_VANISHED" | "NOT_AVAILABLE_IN_REGION" | "NO_SETLIST_FOR_BAND" | "SETLIST_MAY_BE_STALE" | "SELECTED_FROM" | "BANDS_OMITTED_FOR_LENGTH" | "SETLIST_TRUNCATED" | "RESUMED_MID_INSERTION" | "FALLBACK_LONGEST_SETLIST" | "USED_YOUR_PREVIOUS_CHOICE" | "USER_DECLINED" | "SETLIST_CORRECTED_SINCE_SELECTION" | "RESCORED_AFTER_ALGORITHM_UPDATE" | "SELECTED_SETLIST_UNAVAILABLE" | null;
            reasonParams?: {
                [key: string]: string | null;
            } | null;
            candidates?: components["schemas"]["PendingChoiceCandidateOutput.jsonld"][];
        };
        /** @description A generated playlist for a concert, with its per-song report (US-1, US-2). */
        "Playlist.PlaylistOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            id?: number;
            concertId?: number;
            provider?: string;
            name?: string;
            description?: string | null;
            externalUrl?: string | null;
            /**
             * @description The provider's embeddable player URL, or null when the provider offers none, the playlist
             *     has no provider-side id yet, or the provider cannot be resolved (D-211).
             */
            embedUrl?: string | null;
            /** @enum {string|null} */
            resultKind?: "complete" | "partial" | "no_source_material" | "no_tracks_matched" | null;
            /**
             * @description Non-null only when `resultKind === ResultKind::NoSourceMaterial` (D-184).
             * @enum {string|null}
             */
            noSetlistCause?: "band_unknown" | "band_ambiguous" | "no_setlist_for_show" | "identity_unavailable" | null;
            matchRate?: number;
            /** Format: date-time */
            createdAt?: string;
            report?: components["schemas"]["ReportEntryOutput.jsonld"][];
            tracks?: components["schemas"]["PlaylistTrackOutput.jsonld"][];
            sourceSetlists?: components["schemas"]["SourceSetlistOutput.jsonld"][];
        };
        /** @description Normal mode, step 1. 422 unless state = awaiting_setlist_choice. Zero setlist.fm calls — a pure projection of the persisted candidateSetlists. */
        "PlaylistGenerationJob.CandidateSetlistsOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            jobId?: number;
            /** Format: date-time */
            expiresAt?: string;
            concertId?: number;
            bands?: components["schemas"]["CandidateSetlistBandOutput.jsonld"][];
        };
        /** @description Normal mode, step 2. 422 unless state = awaiting_version_choice. No provider search — a pure projection of the persisted pendingChoices; no raw confidence number is ever exposed here. */
        "PlaylistGenerationJob.PendingChoicesOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            jobId?: number;
            /** Format: date-time */
            expiresAt?: string;
            songsTotal?: number;
            autoResolvedCount?: number;
            choicesRequiredCount?: number;
            autoResolved?: components["schemas"]["PendingChoiceAutoResolvedOutput.jsonld"][];
            decisions?: components["schemas"]["PendingChoiceDecisionOutput.jsonld"][];
        };
        /** @description Starts (or returns the already-live) playlist generation for a concert. Zero provider and zero setlist.fm calls happen on this request thread (AC-1.1); a second POST for the same live (concert, provider) returns 200 with the existing job, never a 409 (D-129). */
        "PlaylistGenerationJob.PlaylistGenerationJobOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            id?: number;
            concertId?: number;
            provider?: string;
            mode?: string;
            /** @enum {string} */
            state?: "queued" | "resolving_setlist" | "awaiting_setlist_choice" | "matching" | "awaiting_version_choice" | "building" | "blocked" | "completed" | "failed" | "expired" | "cancelled";
            currentStage?: string | null;
            songsTotal?: number;
            songsProcessed?: number;
            estimatedSecondsRemaining?: number | null;
            /** @enum {string|null} */
            blockedReason?: "setlistfm_budget" | "provider_quota" | "provider_rate_limit" | "needs_reauth" | "provider_disabled" | "upstream_unavailable" | null;
            /** Format: date-time */
            resumableAfter?: string | null;
            /** @enum {string|null} */
            failureReason?: "creation_indeterminate" | "unknown_provider" | "block_cycles_exhausted" | null;
            /** @enum {string|null} */
            resultKind?: "complete" | "partial" | "no_source_material" | "no_tracks_matched" | null;
            /**
             * @description Non-null only when `resultKind === ResultKind::NoSourceMaterial` (D-184).
             * @enum {string|null}
             */
            noSetlistCause?: "band_unknown" | "band_ambiguous" | "no_setlist_for_show" | "identity_unavailable" | null;
            playlistId?: number | null;
            matchedCount?: number;
            lowConfidenceCount?: number;
            notFoundCount?: number;
            skippedCount?: number;
            regionRestrictedCount?: number;
            /** Format: date-time */
            createdAt?: string;
            /** Format: date-time */
            startedAt?: string | null;
            /** Format: date-time */
            finishedAt?: string | null;
            /** @description D-209/AC-9.1: null until the job's version step has been reached at least once. */
            choicesRequiredCount?: number | null;
            choicesMadeCount?: number | null;
        };
        /** @description T-05: awaiting_setlist_choice -> matching. 422 on wrong state, an unknown bandId, a setlistfmId not among that band's candidates, or a qualifying band left unanswered. */
        "PlaylistGenerationJob.SetlistChoiceInput": {
            choices: components["schemas"]["SetlistChoiceItemInput"][];
        };
        /** @description Starts (or returns the already-live) playlist generation for a concert. Zero provider and zero setlist.fm calls happen on this request thread (AC-1.1); a second POST for the same live (concert, provider) returns 200 with the existing job, never a 409 (D-129). */
        "PlaylistGenerationJob.StartGenerationInput": {
            concertId: number | null;
            provider?: string | null;
            /** @enum {string|null} */
            mode?: "fast" | "normal" | null;
            /** @description AC-4.3: only meaningful against an `expired` job — enforced in the processor (422 otherwise). */
            resumeFromJobId?: number | null;
        };
        /** @description T-08: awaiting_version_choice -> building. Full replacement, idempotent while still suspended (D-192). 422 on wrong state, an unknown sourcePosition, or a providerTrackId not among that song's persisted candidates. */
        "PlaylistGenerationJob.VersionChoicesInput": {
            choices: components["schemas"]["VersionChoiceItemInput"][];
        };
        "PlaylistTrackOutput.jsonld": {
            ordinal?: number;
            sourcePosition?: number;
            segmentIndex?: number | null;
            bandName?: string;
            sourceTitle?: string;
            providerTrackId?: string | null;
            confidence?: number | null;
            /** @enum {string} */
            outcome?: "pending" | "matched" | "matched_low_confidence" | "skipped" | "not_found" | "region_restricted";
            /** @enum {string|null} */
            reasonCode?: "COVER_OF" | "LIVE_VERSION_ONLY" | "LOW_CONFIDENCE_MATCH" | "TAPE_NOT_PERFORMED" | "PERFORMANCE_ARTIFACT" | "TRACK_NOT_IN_CATALOG" | "TRACK_VANISHED" | "NOT_AVAILABLE_IN_REGION" | "NO_SETLIST_FOR_BAND" | "SETLIST_MAY_BE_STALE" | "SELECTED_FROM" | "BANDS_OMITTED_FOR_LENGTH" | "SETLIST_TRUNCATED" | "RESUMED_MID_INSERTION" | "FALLBACK_LONGEST_SETLIST" | "USED_YOUR_PREVIOUS_CHOICE" | "USER_DECLINED" | "SETLIST_CORRECTED_SINCE_SELECTION" | "RESCORED_AFTER_ALGORITHM_UPDATE" | "SELECTED_SETLIST_UNAVAILABLE" | null;
            reasonParams?: {
                [key: string]: string | null;
            } | null;
        };
        /** @description Which streaming providers are offered right now, and how playback should render — read by the client at startup (US-6). */
        "ProviderConfig.ProviderConfigOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            key?: string;
            displayName?: string;
            enabled?: boolean;
            /** @enum {string} */
            playbackMode?: "embed" | "deeplink" | "off";
            isDefault?: boolean;
        };
        /**
         * @description `POST /api/token/refresh` (US-4). Every call rotates the presented refresh token — it is marked
         *     used and a new one takes its place, sharing the same family (AC-4.1). Reuse of an
         *     already-rotated token is treated as theft and kills the family (AC-4.4), subject to the
         *     grace-window mitigation in {@see \App\Service\Security\RefreshTokenService} (R-3).
         */
        "Refresh.RefreshInput": {
            refreshToken?: string | null;
        };
        /**
         * @description `POST /api/token/refresh` (US-4). Every call rotates the presented refresh token — it is marked
         *     used and a new one takes its place, sharing the same family (AC-4.1). Reuse of an
         *     already-rotated token is treated as theft and kills the family (AC-4.4), subject to the
         *     grace-window mitigation in {@see \App\Service\Security\RefreshTokenService} (R-3).
         */
        "Refresh.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            accessToken?: string;
            tokenType?: string;
            expiresIn?: number;
            /** @description Present only for `X-Client-Platform: native` requests — see {@see LoginOutput}. */
            refreshToken?: string | null;
        };
        "ReportEntryOutput.jsonld": {
            /** @enum {string} */
            code?: "COVER_OF" | "LIVE_VERSION_ONLY" | "LOW_CONFIDENCE_MATCH" | "TAPE_NOT_PERFORMED" | "PERFORMANCE_ARTIFACT" | "TRACK_NOT_IN_CATALOG" | "TRACK_VANISHED" | "NOT_AVAILABLE_IN_REGION" | "NO_SETLIST_FOR_BAND" | "SETLIST_MAY_BE_STALE" | "SELECTED_FROM" | "BANDS_OMITTED_FOR_LENGTH" | "SETLIST_TRUNCATED" | "RESUMED_MID_INSERTION" | "FALLBACK_LONGEST_SETLIST" | "USED_YOUR_PREVIOUS_CHOICE" | "USER_DECLINED" | "SETLIST_CORRECTED_SINCE_SELECTION" | "RESCORED_AFTER_ALGORITHM_UPDATE" | "SELECTED_SETLIST_UNAVAILABLE";
            params?: {
                [key: string]: string | null;
            };
        };
        /** @description One show's full song list, in playing order (US-4). */
        "Setlist.SetlistDetailOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            state?: string;
            setlistfmId?: string | null;
            eventDate?: string | null;
            venueName?: string | null;
            venueCity?: string | null;
            venueCountry?: string | null;
            tourName?: string | null;
            isEmpty?: boolean;
            songs?: components["schemas"]["SongOutput.jsonld"][];
            freshness?: components["schemas"]["FreshnessEnvelope.jsonld"];
        };
        SetlistChoiceItemInput: {
            bandId: number | null;
            setlistfmId: string | null;
        };
        "SetlistSummaryOutput.jsonld": {
            setlistfmId?: string;
            eventDate?: string;
            venueName?: string | null;
            venueCity?: string | null;
            venueCountry?: string | null;
            tourName?: string | null;
            songCount?: number;
        };
        "SongOutput.jsonld": {
            id?: number | null;
            position?: number;
            setLabel?: string | null;
            title?: string;
            coverOfName?: string | null;
            coverOfMbid?: string | null;
            withName?: string | null;
            info?: string | null;
            isTape?: boolean;
        };
        "SourceSetlistOutput.jsonld": {
            bandName?: string;
            setlistfmId?: string;
            url?: string | null;
        };
        /** @description A user's link to one streaming provider — status, scopes and identity, never a token (US-2, US-3). */
        "StreamingAccount.StreamingAccountOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            id?: number;
            provider?: string;
            providerDisplayName?: string | null;
            providerAccountId?: string;
            scopes?: string[];
            /** Format: date-time */
            linkedAt?: string;
            status?: string;
        };
        /** @description `POST /api/streaming/link` (US-1, AC-1.1). Starts the OAuth round trip for a given provider key. */
        "StreamingLink.StreamingLinkStartInput": {
            provider: string;
        };
        /** @description `POST /api/streaming/link` (US-1, AC-1.1). Starts the OAuth round trip for a given provider key. */
        "StreamingLink.StreamingLinkStartOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            authorizationUrl?: string;
        };
        /** @description `GET /api/streaming/link-results/{ref}` (AC-1.7, AC-1.8, AC-8.7). */
        "StreamingLinkResult.StreamingLinkResultOutput.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            provider?: string;
            success?: boolean;
            reason?: string | null;
        };
        /** @description Account registration. Roles are always exactly ["ROLE_USER"], assigned server-side — this endpoint has no path to any other role (US-10). */
        "User.RegisterUserInput": {
            /**
             * Format: email
             * @default
             */
            email: string;
            /**
             * @description Policy (AC-1.4): 12–4096 characters (4096 is the bcrypt/argon input bound), and rejected if
             *     it appears in Symfony's compromised-password check. Hashed with the auto password hasher
             *     (AC-1.5) — no algorithm is named here or anywhere in application code.
             * @default
             */
            password: string;
        };
        /** @description Account registration. Roles are always exactly ["ROLE_USER"], assigned server-side — this endpoint has no path to any other role (US-10). */
        "User.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            id?: number;
            email?: string;
            emailVerified?: boolean;
            /** Format: date-time */
            createdAt?: string;
        };
        VenueData: {
            name?: string | null;
            city?: string | null;
            countryCode?: string | null;
        };
        "VenueData.jsonld": {
            name?: string | null;
            city?: string | null;
            countryCode?: string | null;
        };
        VersionChoiceItemInput: {
            sourcePosition: number | null;
            segmentIndex?: number | null;
            providerTrackId?: string | null;
        };
    };
    responses: never;
    parameters: never;
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    "api_band-searches_get": {
        parameters: {
            query: {
                /** @description Free-text band name to search for on setlist.fm. */
                name: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description BandSearch resource */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["BandSearch.BandSearchOutput.jsonld"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_bands_bandIdsetlists_get: {
        parameters: {
            query?: {
                /** @description Page number, over the cached index (D-31, AC-3.5). */
                page?: number;
                /** @description Page size, capped at 100 (D-31). */
                itemsPerPage?: number;
            };
            header?: never;
            path: {
                /** @description BandSetlists identifier */
                bandId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description BandSetlists resource */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["BandSetlists.BandSetlistsOutput.jsonld"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_concerts_get_collection: {
        parameters: {
            query?: {
                /** @description The collection page number */
                page?: number;
                /** @description Filter by upcoming/past (D-24). Omit to return both. An unrecognised value is a 422 (AC-3.3). */
                status?: "upcoming" | "past";
                /** @description Filter to concerts whose lineup contains a band matching this normalized substring (US-4, AC-4.2). */
                band?: string;
                /** @description Sort by date. Default: ascending for status=upcoming, descending otherwise (AC-3.4). */
                "order[date]"?: "asc" | "desc";
                /** @description Page size, capped at 100 (D-31, AC-3.5). */
                itemsPerPage?: number;
                /** @description Filter by whether the current user has written a review for this concert (D-241, AC-6.6). Omit to return both. */
                reviewed?: "true" | "false";
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Concert collection */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["HydraCollectionBaseSchema"] & {
                        member: components["schemas"]["Concert.ConcertOutput.jsonld"][];
                    };
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_concerts_post: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description The new Concert resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["Concert.ConcertInput"];
            };
        };
        responses: {
            /** @description Concert resource created */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["Concert.ConcertOutput.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    api_concerts_id_get: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Concert identifier */
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Concert resource */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["Concert.ConcertOutput.jsonld"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_concerts_id_delete: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Concert identifier */
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Concert resource deleted */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_concerts_id_patch: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Concert identifier */
                id: string;
            };
            cookie?: never;
        };
        /** @description The updated Concert resource */
        requestBody: {
            content: {
                "application/merge-patch+json": components["schemas"]["Concert.ConcertPatchInput.jsonMergePatch"];
            };
        };
        responses: {
            /** @description Concert resource updated */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["Concert.ConcertOutput.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    api_concerts_concertIdreview_get: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description ConcertReview identifier */
                concertId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description ConcertReview resource */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["ConcertReview.ConcertReviewOutput.jsonld"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_concerts_concertIdreview_put: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description ConcertReview identifier */
                concertId: string;
            };
            cookie?: never;
        };
        /** @description The updated ConcertReview resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["ConcertReview.ConcertReviewInput"];
            };
        };
        responses: {
            /** @description ConcertReview resource updated */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["ConcertReview.ConcertReviewOutput.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    api_concerts_concertIdreview_delete: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description ConcertReview identifier */
                concertId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description ConcertReview resource deleted */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    "api_email-verificationconfirm_post": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description The new EmailVerificationConfirm resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["EmailVerificationConfirm.EmailVerificationConfirmInput"];
            };
        };
        responses: {
            /** @description EmailVerificationConfirm resource created */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    "api_email-verificationresend_post": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description EmailVerificationResend resource created */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["EmailVerificationResend.GenericAck.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    api_health_get: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Health resource */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["Health.jsonld"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description At least one dependency is unhealthy. The body reports the status of every dependency, healthy and unhealthy alike. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["Health.jsonld"];
                };
            };
        };
    };
    api_login_post: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description The new Login resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["Login.LoginInput"];
            };
        };
        responses: {
            /** @description Login resource created */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["Login.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    api_logout_post: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description The new Logout resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["Logout.LogoutInput"];
            };
        };
        responses: {
            /** @description Logout resource created */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    api_me_get: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Me resource */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["Me.jsonld"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    "api_password-resetconfirm_post": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description The new PasswordResetConfirm resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["PasswordResetConfirm.PasswordResetConfirmInput"];
            };
        };
        responses: {
            /** @description PasswordResetConfirm resource created */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    "api_password-resetrequest_post": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description The new PasswordResetRequest resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["PasswordResetRequest.PasswordResetRequestInput"];
            };
        };
        responses: {
            /** @description PasswordResetRequest resource created */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["PasswordResetRequest.GenericAck.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    api_playlists_get_collection: {
        parameters: {
            query?: {
                /** @description The collection page number */
                page?: number;
                /** @description Filter to playlists for one concert. */
                concertId?: number;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Playlist collection */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["HydraCollectionBaseSchema"] & {
                        member: components["schemas"]["Playlist.PlaylistOutput.jsonld"][];
                    };
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_playlists_id_get: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Playlist identifier */
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Playlist resource */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["Playlist.PlaylistOutput.jsonld"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_playlists_id_delete: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Playlist identifier */
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Playlist resource deleted */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    "api_playlist-generation-jobs_get_collection": {
        parameters: {
            query?: {
                /** @description The collection page number */
                page?: number;
                /** @description Filter to jobs for one concert. */
                concertId?: number;
                /** @description Filter to jobs in one state. */
                state?: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description PlaylistGenerationJob collection */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["HydraCollectionBaseSchema"] & {
                        member: components["schemas"]["PlaylistGenerationJob.PlaylistGenerationJobOutput.jsonld"][];
                    };
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    "api_playlist-generation-jobs_post": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description The new PlaylistGenerationJob resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["PlaylistGenerationJob.StartGenerationInput"];
            };
        };
        responses: {
            /** @description PlaylistGenerationJob resource created */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["PlaylistGenerationJob.PlaylistGenerationJobOutput.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    "api_playlist-generation-jobs_id_get": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description PlaylistGenerationJob identifier */
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description PlaylistGenerationJob resource */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["PlaylistGenerationJob.PlaylistGenerationJobOutput.jsonld"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    "api_playlist-generation-jobs_idcancel_post": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description PlaylistGenerationJob identifier */
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description PlaylistGenerationJob resource created */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["PlaylistGenerationJob.PlaylistGenerationJobOutput.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    "api_playlist-generation-jobs_idcandidate-setlists_get": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description PlaylistGenerationJob identifier */
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description PlaylistGenerationJob resource */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["PlaylistGenerationJob.CandidateSetlistsOutput.jsonld"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    "api_playlist-generation-jobs_idcreate-anyway_post": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description PlaylistGenerationJob identifier */
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description PlaylistGenerationJob resource created */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["PlaylistGenerationJob.PlaylistGenerationJobOutput.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    "api_playlist-generation-jobs_idpending-choices_get": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description PlaylistGenerationJob identifier */
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description PlaylistGenerationJob resource */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["PlaylistGenerationJob.PendingChoicesOutput.jsonld"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    "api_playlist-generation-jobs_idretry_post": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description PlaylistGenerationJob identifier */
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description PlaylistGenerationJob resource created */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["PlaylistGenerationJob.PlaylistGenerationJobOutput.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    "api_playlist-generation-jobs_idsetlist-choice_post": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description PlaylistGenerationJob identifier */
                id: string;
            };
            cookie?: never;
        };
        /** @description The new PlaylistGenerationJob resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["PlaylistGenerationJob.SetlistChoiceInput"];
            };
        };
        responses: {
            /** @description PlaylistGenerationJob resource created */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["PlaylistGenerationJob.PlaylistGenerationJobOutput.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    "api_playlist-generation-jobs_idversion-choices_post": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description PlaylistGenerationJob identifier */
                id: string;
            };
            cookie?: never;
        };
        /** @description The new PlaylistGenerationJob resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["PlaylistGenerationJob.VersionChoicesInput"];
            };
        };
        responses: {
            /** @description PlaylistGenerationJob resource created */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["PlaylistGenerationJob.PlaylistGenerationJobOutput.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    api_configproviders_get_collection: {
        parameters: {
            query?: {
                /** @description The collection page number */
                page?: number;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description ProviderConfig collection */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["HydraCollectionBaseSchema"] & {
                        member: components["schemas"]["ProviderConfig.ProviderConfigOutput.jsonld"][];
                    };
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_tokenrefresh_post: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description The new Refresh resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["Refresh.RefreshInput"];
            };
        };
        responses: {
            /** @description Refresh resource created */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["Refresh.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    api_setlists_setlistfmId_get: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Setlist identifier */
                setlistfmId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Setlist resource */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["Setlist.SetlistDetailOutput.jsonld"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_streamingaccounts_get_collection: {
        parameters: {
            query?: {
                /** @description The collection page number */
                page?: number;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description StreamingAccount collection */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["HydraCollectionBaseSchema"] & {
                        member: components["schemas"]["StreamingAccount.StreamingAccountOutput.jsonld"][];
                    };
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_streamingaccounts_id_delete: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description StreamingAccount identifier */
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description StreamingAccount resource deleted */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_streaminglink_post: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description The new StreamingLink resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["StreamingLink.StreamingLinkStartInput"];
            };
        };
        responses: {
            /** @description StreamingLink resource created */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["StreamingLink.StreamingLinkStartOutput.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
    "api_streaminglink-results_ref_get": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description StreamingLinkResult identifier */
                ref: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description StreamingLinkResult resource */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["StreamingLinkResult.StreamingLinkResultOutput.jsonld"];
                };
            };
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    api_users_post: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description The new User resource */
        requestBody: {
            content: {
                "application/ld+json": components["schemas"]["User.RegisterUserInput"];
            };
        };
        responses: {
            /** @description User resource created */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/ld+json": components["schemas"]["User.jsonld"];
                };
            };
            /** @description Invalid input */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["Error"];
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An error occurred */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/problem+json": components["schemas"]["ConstraintViolation"];
                    "application/json": components["schemas"]["ConstraintViolation"];
                };
            };
        };
    };
}
