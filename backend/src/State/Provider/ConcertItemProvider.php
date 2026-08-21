<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ConcertOutput;
use App\Security\Voter\ConcertVoter;
use App\State\ConcertLocator;
use App\State\ConcertOutputMapper;

/**
 * `GET /api/concerts/{id}` (US-3 item form, US-7). Ownership is enforced twice — see
 * `App\State\ConcertLocator`.
 *
 * @implements ProviderInterface<ConcertOutput>
 */
final readonly class ConcertItemProvider implements ProviderInterface
{
    public function __construct(
        private ConcertLocator $locator,
        private ConcertOutputMapper $mapper,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ConcertOutput
    {
        $concert = $this->locator->locate($uriVariables['id'] ?? null, ConcertVoter::VIEW);

        return $this->mapper->map($concert);
    }
}
