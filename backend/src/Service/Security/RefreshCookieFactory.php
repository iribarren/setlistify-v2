<?php

declare(strict_types=1);

namespace App\Service\Security;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Builds the refresh-token cookie: httpOnly, `Secure`, `SameSite=Strict` (D-18).
 *
 * **Deviation from D-18's stated path scoping.** The spec describes the cookie as scoped to "the
 * refresh endpoint only". In implementation, `/api/logout` also has to read it (AC-5.1 revokes the
 * *presented* refresh token's family — there is no other way to identify which family that is on
 * web, since the access token carries no refresh-token/family reference by design). A cookie
 * `Path=/api/token/refresh` is never sent by the browser to `/api/logout` at all — they share no
 * URL prefix narrower than `/api` — so path-scoping it that tightly makes web logout silently a
 * no-op. The cookie is therefore scoped to `/api` instead: every API request carries it, but
 * `RefreshProcessor` and `LogoutProcessor` are the *only* two processors that ever read it, so the
 * practical CSRF surface D-18 argues about is unchanged — a forged cross-site POST to any other
 * endpoint still can't do anything with a cookie that endpoint never looks at.
 */
final readonly class RefreshCookieFactory
{
    public const string COOKIE_NAME = 'refresh_token';
    public const string COOKIE_PATH = '/api';

    public function __construct(
        private string $refreshTokenTtl,
        private bool $secure,
    ) {
    }

    public function create(string $plaintextToken): Cookie
    {
        return Cookie::create(
            name: self::COOKIE_NAME,
            value: $plaintextToken,
            expire: time() + (int) $this->refreshTokenTtl,
            path: self::COOKIE_PATH,
            domain: null,
            secure: $this->secure,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_STRICT,
        );
    }

    /** Overwrites the cookie with an already-expired one, so the browser drops it (AC-5.2). */
    public function clear(): Cookie
    {
        return Cookie::create(
            name: self::COOKIE_NAME,
            value: '',
            expire: 1,
            path: self::COOKIE_PATH,
            domain: null,
            secure: $this->secure,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_STRICT,
        );
    }
}
