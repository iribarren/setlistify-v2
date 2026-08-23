<?php

declare(strict_types=1);

namespace App\Service\Playlist\Model;

/**
 * The three — and only three — routes to `failed` (spec 14 §5): F-14 (creation indeterminate),
 * F-15 (unknown provider, a deployment defect) and a block-cycle count above
 * `GENERATION_MAX_BLOCK_CYCLES`. "Some songs were missing" is never among them.
 */
enum FailureReason: string
{
    case CreationIndeterminate = 'creation_indeterminate';
    case UnknownProvider = 'unknown_provider';
    case BlockCyclesExhausted = 'block_cycles_exhausted';
}
