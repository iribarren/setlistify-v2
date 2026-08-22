<?php

declare(strict_types=1);

namespace App\Service\Provider;

/**
 * How a generated playlist is played on the concert page (`docs/architecture.md` §7). This is a
 * runtime, backoffice-controlled axis, independent of {@see ProviderConfig::$enabled} (D-97) — a
 * provider may be enabled for linking/generation with `playbackMode = off` and no in-app playback
 * surface at all (the Non-Streaming SDA posture — see `docs/external-apis.md`'s reference-provider
 * section for the full legal position this enum makes reversible at runtime).
 *
 * This file never spells out any reference provider's capitalized product name — the architecture
 * isolation test bans that symbol outside its own adapter directory, and this enum is deliberately
 * provider-agnostic; the specific policy consequence is stated in `docs/external-apis.md` and in
 * `App\Controller\Admin\ProviderSettingCrudController`'s admin-facing help text.
 */
enum PlaybackMode: string
{
    /** Plays the provider's audio in-app (e.g. an iframe embed). Likely a Streaming SDA under a strict provider's terms. */
    case Embed = 'embed';

    /** Hands off playback to the provider's own app via a deep link. Non-Streaming SDA under those same terms. */
    case Deeplink = 'deeplink';

    /** No in-app playback surface at all. */
    case Off = 'off';
}
