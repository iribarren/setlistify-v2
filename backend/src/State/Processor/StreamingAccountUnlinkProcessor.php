<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\StreamingAccountRepository;
use App\Security\Voter\StreamingAccountVoter;
use App\State\StreamingAccountLocator;

/**
 * `DELETE /api/streaming/accounts/{id}` (US-3, AC-3.1, AC-3.5). Hard delete — no soft-delete flag,
 * both ciphertext token columns go with the row.
 *
 * **AC-3.2/D-81 deviation, recorded here**: the frozen `StreamingProviderInterface` (D-71, nine
 * methods) has no revocation method — the reference provider exposes none to call (AC-3.3,
 * `docs/external-apis.md`), and adding a tenth method for a capability zero current adapters
 * implement would pre-empt D-71's own "capability value object, not a wider interface" escape valve
 * for the day a provider that DOES support revocation arrives. This branch therefore does not
 * attempt a best-effort revoke call before deleting; deletion alone is what AC-3.1 requires, and
 * AC-3.3's honesty about the missing revocation endpoint is carried entirely in the client-facing
 * copy (frontend work, out of this branch), not in adapter code that would just be a permanent
 * no-op.
 *
 * @implements ProcessorInterface<null, void>
 */
final readonly class StreamingAccountUnlinkProcessor implements ProcessorInterface
{
    public function __construct(
        private StreamingAccountLocator $locator,
        private StreamingAccountRepository $repository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $account = $this->locator->locate($uriVariables['id'] ?? null, StreamingAccountVoter::DELETE);

        $this->repository->remove($account);
    }
}
