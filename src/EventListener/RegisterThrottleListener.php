<?php

namespace App\EventListener;

use App\Service\AttemptLimiter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Plafonne les créations de compte par adresse IP : chaque inscription envoie un e-mail, et un
 * robot pourrait s'en servir pour créer des comptes en masse ou faire partir du courrier indésirable.
 *
 * Le compteur est alimenté par `UserRegisterProcessor` (inscriptions réussies seulement).
 * ⚠️ Derrière un proxy, configurer `trusted_proxies`, sinon tout le monde partage la même adresse.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class RegisterThrottleListener
{
    public const SCOPE = 'register';
    public const MAX_PER_WINDOW = 20;
    public const WINDOW_SECONDS = 3600;

    public function __construct(private readonly AttemptLimiter $limiter)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || $request->getPathInfo() !== '/api/register') {
            return;
        }

        if ($this->limiter->isBlocked(self::SCOPE, (string) $request->getClientIp(), self::MAX_PER_WINDOW)) {
            $event->setResponse(new JsonResponse(
                ['message' => AttemptLimiter::MESSAGE, 'status' => JsonResponse::HTTP_TOO_MANY_REQUESTS],
                JsonResponse::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) self::WINDOW_SECONDS],
            ));
        }
    }
}
