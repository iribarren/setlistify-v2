<?php

declare(strict_types=1);

namespace App\Service\Playlist\Model;

/** The six `blocked` reasons (spec 13 §4 / spec 14 §5's F-01, F-04, F-05, F-06, F-07, F-12). */
enum BlockedReason: string
{
    case SetlistfmBudget = 'setlistfm_budget';
    case ProviderQuota = 'provider_quota';
    case ProviderRateLimit = 'provider_rate_limit';
    case NeedsReauth = 'needs_reauth';
    case ProviderDisabled = 'provider_disabled';
    case UpstreamUnavailable = 'upstream_unavailable';
}
