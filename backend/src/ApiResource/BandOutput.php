<?php

declare(strict_types=1);

namespace App\ApiResource;

final readonly class BandOutput
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}
