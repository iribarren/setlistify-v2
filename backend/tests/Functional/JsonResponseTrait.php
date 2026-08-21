<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Shared JSON-decoding helper for functional tests — keeps `json_decode`'s `mixed` return type
 * from leaking into every assertion (PHPStan level 9, D-8).
 */
trait JsonResponseTrait
{
    /**
     * @return array<string, mixed>
     */
    protected static function decodeJsonObject(string $json): array
    {
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($data);

        /** @var array<string, mixed> $decoded */
        $decoded = $data;

        return $decoded;
    }
}
