<?php

declare(strict_types=1);

namespace App\Service\Playlist\Model;

/** `fast` (this feature) or `normal` (prompt 17) — read at exactly two guards (AC-6.2). */
enum JobMode: string
{
    case Fast = 'fast';
    case Normal = 'normal';
}
