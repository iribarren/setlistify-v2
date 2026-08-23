<?php

declare(strict_types=1);

namespace App\Service\Playlist\Exception;

/**
 * F-15: `PreflightStage`'s translation of `App\Service\Streaming\UnknownProviderException` into the
 * pipeline's own vocabulary, so `PlaylistPipeline` can catch one type and hand
 * `JobStateMachine::fail()` the right `FailureReason` (deployment defect — a provider key with no
 * registered adapter) without reaching into `JobStateMachine` itself from inside a stage.
 */
final class UnknownProviderInPipelineException extends \RuntimeException
{
    public function __construct(public readonly string $providerKey)
    {
        parent::__construct(\sprintf('No streaming provider registered for key "%s".', $providerKey));
    }
}
