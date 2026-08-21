<?php

declare(strict_types=1);

namespace App\Service\Concert;

use App\ApiResource\LineupEntryInput;
use App\Entity\Band;
use App\Repository\BandRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Small, stateless helpers shared by `App\State\Processor\ConcertCreateProcessor` and
 * `ConcertUpdateProcessor` so date/time parsing and lineup-entry-to-band resolution stay in one
 * place. Every value passed in is assumed already validated by `App\Validator\ConcertDateRange`,
 * the `HH:MM` regex on `App\ApiResource\ConcertInput`/`ConcertPatchInput`, and
 * `App\Validator\ValidConcertInputValidator` — this class does not re-validate, it only converts.
 */
final readonly class ConcertFieldParser
{
    public function __construct(
        private BandResolver $bandResolver,
        private BandRepository $bandRepository,
    ) {
    }

    public function parseDate(string $date): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        \assert(false !== $parsed, 'Date must already be validated by ConcertDateRange.');

        return $parsed;
    }

    public function parseTime(?string $time): ?\DateTimeImmutable
    {
        if (null === $time) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!H:i', $time, new \DateTimeZone('UTC'));
        \assert(false !== $parsed, 'Time must already be validated by the HH:MM regex.');

        return $parsed;
    }

    /**
     * Resolves one lineup entry to a `Band`. `App\Validator\ValidConcertInputValidator` has already
     * confirmed exactly one of `name`/`bandId` is set and, for `bandId`, that the band exists — the
     * `NotFoundHttpException` here is an unreachable defensive fallback, not a real 404 path.
     */
    public function resolveBand(LineupEntryInput $entry): Band
    {
        if (null !== $entry->name) {
            return $this->bandResolver->resolve($entry->name);
        }

        \assert(null !== $entry->bandId);

        return $this->bandRepository->find($entry->bandId) ?? throw new NotFoundHttpException('No band exists with this id.');
    }
}
