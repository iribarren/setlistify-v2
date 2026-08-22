<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\StreamingAccountOutput;
use App\Entity\StreamingAccount;

final class StreamingAccountOutputMapper
{
    public function map(StreamingAccount $account): StreamingAccountOutput
    {
        return new StreamingAccountOutput(
            id: $account->getId() ?? 0,
            provider: $account->getProvider(),
            providerDisplayName: $account->getProviderDisplayName(),
            providerAccountId: $account->getProviderAccountId(),
            scopes: $account->getScopes(),
            linkedAt: $account->getLinkedAt(),
            status: $account->getStatus(),
        );
    }
}
