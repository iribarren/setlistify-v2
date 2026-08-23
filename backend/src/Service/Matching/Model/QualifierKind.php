<?php

declare(strict_types=1);

namespace App\Service\Matching\Model;

/**
 * The classification of one N4-extracted parenthetical/trailing segment (spec 12 §1). `TitleContinuation`
 * is the deliberate default: an unrecognized segment returns to the comparison core rather than being
 * discarded, because a missed title fragment is a far more common failure than a missed version marker.
 */
enum QualifierKind: string
{
    case Version = 'version';
    case FeaturedCredit = 'featured_credit';
    case TitleContinuation = 'title_continuation';
}
