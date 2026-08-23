<?php

declare(strict_types=1);

namespace App\Service\Playlist\Exception;

use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\PipelineStage;

/**
 * The pipeline's signal that a precondition of the world is temporarily false — a provider quota,
 * a rate limit, a disabled provider, an expired token, or an unreachable upstream (F-01/F-04/F-05/
 * F-06/F-07/F-12). `BuildPlaylistHandler` catches this and calls `JobStateMachine::block()`;
 * the message is acknowledged, never rethrown — a blocked job is resumed by the sweeper or a user
 * action, never by a Messenger retry.
 */
final class GenerationBlockedException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly BlockedReason $reason,
        public readonly ?\DateTimeImmutable $resumableAfter,
        public readonly ?PipelineStage $stage,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
