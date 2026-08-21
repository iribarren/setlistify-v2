<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events as JwtEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adds the `jti` claim AC-2.3 requires. LexikJWT ships a `RandomJtiEnrichment` for exactly this,
 * but its `PayloadEnrichmentInterface` chain compiles empty in this bundle version/config (verified
 * via `bin/console debug:container lexik_jwt_authentication.payload_enrichment` — zero enrichments
 * wired) — rather than debug a third-party compiler pass, this app owns the one claim it actually
 * needs added.
 *
 * Everything else in the payload (`sub`, `roles`, `iat`, `exp`) is already correct without this
 * listener: `sub` comes from `lexik_jwt_authentication.user_id_claim: sub` reading
 * `App\Entity\User::getSub()`, `roles` is `JWTManager`'s own default, and `iat`/`exp` are added by
 * the JWS encoder itself.
 */
final class JwtPayloadSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            JwtEvents::JWT_CREATED => 'onJwtCreated',
        ];
    }

    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $data = $event->getData();

        if (!isset($data['jti'])) {
            $data['jti'] = Uuid::v4()->toRfc4122();
            $event->setData($data);
        }
    }
}
