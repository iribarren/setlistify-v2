<?php

declare(strict_types=1);

namespace App\Service\Playlist\Model;

/** How a band's setlist was chosen (D-132) — rendered on every playlist, never silent (D-155). */
enum SelectionReason: string
{
    case MostRecentSubstantial = 'most_recent_substantial';
    case FallbackLongest = 'fallback_longest';
    case OnlyOneAvailable = 'only_one_available';
    case UserChosen = 'user_chosen';
}
