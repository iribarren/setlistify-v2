<?php

declare(strict_types=1);

namespace App\State\Provider\Playlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Playlist\PlaylistGenerationJobOutput;
use App\Security\Voter\PlaylistGenerationJobVoter;
use App\State\PlaylistGenerationJobLocator;
use App\State\PlaylistGenerationJobOutputMapper;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /api/playlist-generation-jobs/{id}` (spec 14 §6). Sets the `ETag`/`Retry-After` request
 * attributes `App\EventSubscriber\PlaylistResponseHeadersSubscriber` turns into real headers/304.
 *
 * @implements ProviderInterface<PlaylistGenerationJobOutput>
 */
final readonly class PlaylistGenerationJobItemProvider implements ProviderInterface
{
    public function __construct(
        private PlaylistGenerationJobLocator $locator,
        private PlaylistGenerationJobOutputMapper $mapper,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlaylistGenerationJobOutput
    {
        $job = $this->locator->locate($uriVariables['id'] ?? null, PlaylistGenerationJobVoter::VIEW);

        if (isset($context['request']) && $context['request'] instanceof Request) {
            $context['request']->attributes->set('_playlist_etag', PlaylistGenerationJobOutputMapper::etag($job));
            $context['request']->attributes->set('_playlist_retry_after', PlaylistGenerationJobOutputMapper::retryAfterSeconds($job->getState()));
        }

        return $this->mapper->map($job);
    }
}
