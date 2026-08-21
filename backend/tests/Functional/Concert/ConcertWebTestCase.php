<?php

declare(strict_types=1);

namespace App\Tests\Functional\Concert;

use App\Tests\Functional\Auth\AuthWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared scaffolding for concert-domain functional tests (docs/specs/2026-08-21-concert-domain-api.md).
 * Extends `App\Tests\Functional\Auth\AuthWebTestCase` to reuse its user registration/login helpers
 * and rate-limiter-clearing `createAuthClient()` — every concert test needs an authenticated user
 * first.
 */
abstract class ConcertWebTestCase extends AuthWebTestCase
{
    /** @return array{email: string, password: string, accessToken: string} */
    protected function registerAndLogin(KernelBrowser $client, ?string $email = null): array
    {
        $credentials = $this->registerUser($client, $email);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);

        return [
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'accessToken' => $login['accessToken'],
        ];
    }

    /** @return array<string, string> */
    protected static function authHeaders(string $accessToken): array
    {
        return [
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function createConcert(KernelBrowser $client, string $accessToken, array $payload, int $expectedStatus = Response::HTTP_CREATED): array
    {
        $client->request(
            'POST',
            '/api/concerts',
            server: self::authHeaders($accessToken),
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame($expectedStatus, (string) $client->getResponse()->getContent());

        return self::decodeJsonObject((string) $client->getResponse()->getContent());
    }

    /** @return array<string, mixed> minimal valid payload: a single-band lineup on a given date. */
    protected static function minimalConcertPayload(string $date = '2026-12-24', string $timezone = 'Europe/Madrid', ?string $bandName = null): array
    {
        return [
            'date' => $date,
            'timezone' => $timezone,
            'lineup' => [['name' => $bandName ?? self::uniqueBandName()]],
        ];
    }

    protected static function uniqueBandName(string $label = 'Band'): string
    {
        return \sprintf('%s %s', $label, bin2hex(random_bytes(6)));
    }

    // -- Narrowing helpers -------------------------------------------------
    // `decodeJsonObject()` returns `array<string, mixed>`; PHPStan level 9 (D-8) will not let a
    // `mixed` value be indexed, formatted, or passed to a typed assertion further, so every test
    // that walks into a nested response value (`$data['lineup'][0]['band']['name']`) routes through
    // one of these instead of indexing `$data` directly more than one level deep.

    /** @return array<string, mixed> */
    protected static function asArray(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /** @return list<mixed> */
    protected static function asList(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values($value);
    }

    protected static function asString(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    protected static function asInt(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }

    /** @param array<string, mixed> $data */
    protected static function idOf(array $data): int
    {
        return self::asInt($data['id']);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected static function lineupEntryAt(array $data, int $index): array
    {
        return self::asArray(self::asList($data['lineup'])[$index]);
    }

    /** @param array<string, mixed> $lineupEntry */
    protected static function bandNameOf(array $lineupEntry): string
    {
        return self::asString(self::asArray($lineupEntry['band'])['name']);
    }

    /** @param array<string, mixed> $lineupEntry */
    protected static function bandIdOf(array $lineupEntry): int
    {
        return self::asInt(self::asArray($lineupEntry['band'])['id']);
    }

    /** @param array<string, mixed> $lineupEntry */
    protected static function billingOrderOf(array $lineupEntry): int
    {
        return self::asInt($lineupEntry['billingOrder']);
    }

    /**
     * @param array<string, mixed> $collection a decoded Hydra collection response
     *
     * @return array<string, mixed>
     */
    protected static function memberAt(array $collection, int $index): array
    {
        return self::asArray(self::asList($collection['member'])[$index]);
    }

    /** @return list<mixed> */
    protected static function membersOf(mixed $collection): array
    {
        return self::asList(self::asArray($collection)['member']);
    }
}
