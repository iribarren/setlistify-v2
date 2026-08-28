<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;

/**
 * D-266 (docs/specs/2026-08-27-instant-setlist-refresh.md): the band exists but appears on no
 * concert owned by the caller — `422`, never `403` (the caller IS permitted to do this, on a
 * different band) and never `404` (the band's existence already leaks legitimately through
 * `GET /api/bands/{id}/setlists`, D-66's shared reference data). Same `ProblemExceptionInterface`
 * shape as `App\Service\Provider\ProviderDisabledException`.
 */
final class BandNotOnYourConcertsException extends \RuntimeException implements ProblemExceptionInterface
{
    public function __construct()
    {
        parent::__construct('band_not_on_your_concerts');
    }

    public function getType(): string
    {
        return '/errors/band-not-on-your-concerts';
    }

    public function getTitle(): string
    {
        return 'Band not on any of your concerts';
    }

    public function getStatus(): int
    {
        return 422;
    }

    public function getDetail(): string
    {
        return 'band_not_on_your_concerts';
    }

    public function getInstance(): string
    {
        return '';
    }
}
