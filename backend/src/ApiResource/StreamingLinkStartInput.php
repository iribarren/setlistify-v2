<?php

declare(strict_types=1);

namespace App\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

/** `POST /api/streaming/link` body (AC-1.1). */
final readonly class StreamingLinkStartInput
{
    public function __construct(
        #[Assert\NotBlank]
        public string $provider = '',
    ) {
    }
}
