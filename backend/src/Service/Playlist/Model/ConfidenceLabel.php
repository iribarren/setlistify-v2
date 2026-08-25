<?php

declare(strict_types=1);

namespace App\Service\Playlist\Model;

/**
 * D-204/AC-2.5: the closed vocabulary a version-choice candidate's confidence is rendered as —
 * never a raw score. Backed so the OpenAPI document (and therefore the generated frontend client)
 * carries a real literal union, the same shape as {@see ReportCode}.
 */
enum ConfidenceLabel: string
{
    case TopPick = 'top_pick';
    case OnlyMatch = 'only_match';
    case Alternative = 'alternative';
    case YourPreviousChoice = 'your_previous_choice';
}
