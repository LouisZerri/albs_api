<?php

namespace App\EventListener;

use App\Service\SessionRevoker;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Refuse un JWT émis avant la dernière réinitialisation du mot de passe du compte. */
#[AsEventListener(event: Events::JWT_DECODED)]
final class RevokedTokenListener
{
    public function __construct(private readonly SessionRevoker $revoker)
    {
    }

    public function __invoke(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();
        $email = $payload['email'] ?? null;
        $issuedAt = $payload['iat'] ?? null;

        if (is_string($email) && is_int($issuedAt) && $this->revoker->isRevoked($email, $issuedAt)) {
            $event->markAsInvalid();
        }
    }
}
