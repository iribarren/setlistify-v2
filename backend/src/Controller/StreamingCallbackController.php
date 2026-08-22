<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Streaming\Link\LinkFlowService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The OAuth return leg (D-75, US-8) — a bare browser navigation the provider redirects to, never a
 * client `fetch()` call. Deliberately a plain Symfony controller, not an API Platform resource: it
 * is unauthenticated (no JWT exists on this hop) and its response is an HTTP redirect, not JSON, so
 * it does not belong in the OpenAPI contract any more than the admin backoffice's session routes do
 * (`CLAUDE.md` — "the backoffice is not part of the contract" carries the same reasoning here: this
 * route is not something the Expo client calls directly either).
 *
 * `{provider}` is a route parameter, not a hardcoded path — this controller never mentions a
 * specific provider by name; `LinkFlowService` does all the provider resolution.
 */
final class StreamingCallbackController
{
    public function __construct(
        private readonly LinkFlowService $linkFlowService,
    ) {
    }

    #[Route('/api/streaming/{provider}/callback', name: 'streaming_callback', methods: ['GET'])]
    public function __invoke(string $provider, Request $request): RedirectResponse
    {
        $code = $request->query->get('code');
        $state = $request->query->get('state');
        $error = $request->query->get('error');

        $returnUrl = $this->linkFlowService->completeCallback(
            providerKey: $provider,
            code: \is_string($code) ? $code : null,
            state: \is_string($state) ? $state : null,
            errorParam: \is_string($error) ? $error : null,
        );

        return new RedirectResponse($returnUrl);
    }
}
