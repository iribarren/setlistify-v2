<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\StreamingLinkStartInput;
use App\ApiResource\StreamingLinkStartOutput;
use App\Entity\User;
use App\Service\Security\ClientPlatform;
use App\Service\Streaming\Link\LinkFlowService;
use App\Service\Streaming\UnknownProviderException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /api/streaming/link` (AC-1.1, AC-8.6). An unknown provider key is a 404 — it reveals
 * nothing about which providers exist beyond what `GET /api/config/providers` already publishes.
 *
 * D-94: a *disabled* provider (`App\Service\Provider\ProviderDisabledException`, thrown by
 * `LinkFlowService::start()`) is deliberately **not** caught here — it implements API Platform's
 * `ProblemExceptionInterface` and maps itself to `503` with `type: /errors/provider-unavailable`
 * when left to propagate, the same mechanism `ApiPlatform\State\Exception\
 * ParameterNotSupportedException` uses. Catching and re-throwing it here would be a no-op.
 *
 * @implements ProcessorInterface<StreamingLinkStartInput, StreamingLinkStartOutput>
 */
final readonly class StreamingLinkStartProcessor implements ProcessorInterface
{
    public function __construct(
        private LinkFlowService $linkFlowService,
        private Security $security,
        private ClientPlatform $clientPlatform,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StreamingLinkStartOutput
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || null === $user->getId()) {
            throw new AccessDeniedHttpException();
        }

        /** @var Request $request */
        $request = $context['request'];
        $platform = $this->clientPlatform->isNative($request) ? 'native' : 'web';

        try {
            $url = $this->linkFlowService->start($user->getId(), $data->provider, $platform);
        } catch (UnknownProviderException) {
            throw new NotFoundHttpException();
        }

        return new StreamingLinkStartOutput($url);
    }
}
