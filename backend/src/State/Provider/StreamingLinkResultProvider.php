<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\StreamingLinkResultOutput;
use App\Entity\User;
use App\Service\Streaming\Link\LinkFlowService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/streaming/link-results/{ref}` (AC-1.7, AC-1.8, AC-8.7). Resolving with an unknown,
 * expired, already-consumed or someone-else's reference is a 404 — indistinguishable from any other
 * case, same reasoning as `StreamingAccountLocator`'s cross-owner 404 (D-77).
 *
 * @implements ProviderInterface<StreamingLinkResultOutput>
 */
final readonly class StreamingLinkResultProvider implements ProviderInterface
{
    public function __construct(
        private LinkFlowService $linkFlowService,
        private Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StreamingLinkResultOutput
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || null === $user->getId()) {
            throw new AccessDeniedHttpException();
        }

        $ref = $uriVariables['ref'] ?? null;
        if (!\is_string($ref) || '' === $ref) {
            throw new NotFoundHttpException();
        }

        $result = $this->linkFlowService->resolveResult($user->getId(), $ref);
        if (null === $result) {
            throw new NotFoundHttpException();
        }

        return new StreamingLinkResultOutput(provider: $result->provider, success: $result->success, reason: $result->reason);
    }
}
