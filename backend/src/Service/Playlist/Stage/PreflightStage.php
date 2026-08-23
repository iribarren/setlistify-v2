<?php

declare(strict_types=1);

namespace App\Service\Playlist\Stage;

use App\Entity\PlaylistGenerationJob;
use App\Entity\StreamingAccount;
use App\Service\Playlist\Exception\GenerationBlockedException;
use App\Service\Playlist\Exception\UnknownProviderInPipelineException;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\PipelineStage;
use App\Service\Provider\ProviderAvailability;
use App\Service\Streaming\StreamingProviderLocator;
use Psr\Clock\ClockInterface;

/**
 * Provider enabled? Adapter registered? `StreamingAccount` connected? (spec 14 §3). F-15's
 * unknown-provider defect and F-07's disabled-provider block both originate here, before the
 * message ever advances the job to `resolving_setlist`.
 */
final readonly class PreflightStage
{
    public function __construct(
        private StreamingProviderLocator $locator,
        private ProviderAvailability $providerAvailability,
        private JobStateMachine $stateMachine,
        private ClockInterface $clock,
    ) {
    }

    /** @throws GenerationBlockedException|UnknownProviderInPipelineException (F-15, unrecoverable, caught by the pipeline and turned into `failed`) */
    public function run(PlaylistGenerationJob $job): void
    {
        $providerKey = $job->getProviderKey();

        if (!$this->locator->has($providerKey)) {
            throw new UnknownProviderInPipelineException($providerKey);
        }

        if (!$this->providerAvailability->isAvailable($providerKey)) {
            $resumableAfter = \DateTimeImmutable::createFromInterface($this->clock->now())->modify('+30 minutes');

            throw new GenerationBlockedException(\sprintf('Provider "%s" is disabled.', $providerKey), BlockedReason::ProviderDisabled, $resumableAfter, PipelineStage::Preflight);
        }

        $account = $job->getStreamingAccount();
        if (StreamingAccount::STATUS_CONNECTED !== $account->getStatus()) {
            throw new GenerationBlockedException('Streaming account needs reconnecting.', BlockedReason::NeedsReauth, null, PipelineStage::Preflight);
        }

        $this->stateMachine->startResolvingSetlist($job);
    }
}
