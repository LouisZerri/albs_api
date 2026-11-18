<?php

namespace App\EventListener;

use App\Security\EmailNotVerifiedException;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Connexion refusée pour adresse non confirmée : la réponse porte un `reason`, pour que l'app
 * ouvre l'écran du code au lieu d'afficher une simple erreur.
 */
#[AsEventListener(event: Events::AUTHENTICATION_FAILURE)]
final class LoginFailureReasonListener
{
    public function __invoke(AuthenticationFailureEvent $event): void
    {
        $exception = $event->getException();
        if (!$exception instanceof EmailNotVerifiedException) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'code' => JsonResponse::HTTP_UNAUTHORIZED,
            'message' => $exception->getMessageKey(),
            'reason' => EmailNotVerifiedException::REASON,
        ], JsonResponse::HTTP_UNAUTHORIZED));
    }
}
