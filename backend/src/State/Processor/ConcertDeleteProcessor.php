<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\ConcertRepository;
use App\Security\Voter\ConcertVoter;
use App\State\ConcertLocator;

/**
 * `DELETE /api/concerts/{id}` (US-6). Hard delete, no soft-delete flag (AC-6.5). `ConcertBand` rows
 * cascade with the concert (`Concert::$concertBands`'s `orphanRemoval`, AC-6.2); `Band` rows are
 * never touched by this cascade and survive (AC-6.3).
 *
 * @implements ProcessorInterface<null, void>
 */
final readonly class ConcertDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private ConcertLocator $locator,
        private ConcertRepository $concertRepository,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $concert = $this->locator->locate($uriVariables['id'] ?? null, ConcertVoter::DELETE);

        $this->concertRepository->remove($concert);
    }
}
