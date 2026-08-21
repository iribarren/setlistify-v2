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
        "EmailVerificationConfirm.EmailVerificationConfirmInput": {
            /** @default  */
            token: string;
        };
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
        "Login.LoginInput": {
            /**
             * Format: email
             * @default
             */
            email: string;
            /** @default  */
            password: string;
        };
        "Login.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            accessToken?: string;
            tokenType?: string;
            expiresIn?: number;
            refreshToken?: string | null;
        };
        "Logout.LogoutInput": {
            refreshToken?: string | null;
        };
        /** @description The authenticated user's own identity. */
        "Me.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            id?: number;
            email?: string;
            emailVerified?: boolean;
            roles?: (string | null)[];
            /** Format: date-time */
            createdAt?: string;
        };
        "PasswordResetConfirm.PasswordResetConfirmInput": {
            /** @default  */
            token: string;
            /** @default  */
            password: string;
        };
        "PasswordResetRequest.GenericAck.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            message?: string;
        };
        "PasswordResetRequest.PasswordResetRequestInput": {
            /**
             * Format: email
             * @default
             */
            email: string;
        };
        "Refresh.RefreshInput": {
            refreshToken?: string | null;
        };
        "Refresh.jsonld": components["schemas"]["HydraItemBaseSchema"] & {
            accessToken?: string;
            tokenType?: string;
            expiresIn?: number;
            refreshToken?: string | null;
        };
        /** @description Account registration. Roles are always exactly ["ROLE_USER"], assigned server-side — this endpoint has no path to any other role (US-10). */
        "User.RegisterUserInput": {
            /**
             * Format: email
             * @default
             */
            email: string;
            /** @default  */
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
    };
    responses: never;
    parameters: never;
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
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
