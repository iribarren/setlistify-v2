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
}
export type webhooks = Record<string, never>;
export interface components {
    schemas: {
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
        /** @description Reports whether the application and its dependencies (database, Redis) are actually usable — not just that the container is up. */
        "Health.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            status?: string;
            database?: string;
            redis?: string;
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
    };
    responses: never;
    parameters: never;
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
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
}
