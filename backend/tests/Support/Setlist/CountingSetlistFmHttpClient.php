<?php

declare(strict_types=1);

namespace App\Tests\Support\Setlist;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * D-232/AC-5.6: proves the highlight picker (and review reads/writes generally) spend NONE of the
 * 1,440/day setlist.fm budget. Decorates the real `setlistfm.client` scoped `HttpClientInterface`
 * (`config/services.yaml`) ONLY in `when@test`, counting every `request()` call — a genuine spy on
 * the one door `App\Service\Setlist\SetlistFmClient` is allowed to use (D-58), rather than a grep,
 * per the spec's instruction to use "a test double/spy" here.
 *
 * The counter is deliberately `static`: `Symfony\Bundle\FrameworkBundle\Test\KernelBrowser` rebuilds
 * the whole container (a fresh instance of this class included) on every `$client->request()` call,
 * so instance state set before one request is gone by the time a later request's own calls (if any)
 * would increment it — the same reboot problem
 * `App\Tests\Support\ConcertReview\ConcertReviewRaceInjector` documents and solves the same way.
 */
final class CountingSetlistFmHttpClient implements HttpClientInterface
{
    private static int $requestCount = 0;

    public function __construct(private readonly HttpClientInterface $decorated)
    {
    }

    public function getRequestCount(): int
    {
        return self::$requestCount;
    }

    public function reset(): void
    {
        self::$requestCount = 0;
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        ++self::$requestCount;

        return $this->decorated->request($method, $url, $options);
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->decorated->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return new self($this->decorated->withOptions($options));
    }
}
