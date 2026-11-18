<?php

namespace App\Security;

use App\Service\AttemptLimiter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Ralentit les essais de mot de passe sur `POST /api/login` : après trop d'échecs pour une même
 * adresse, la connexion est refusée pendant quelques minutes, même avec le bon mot de passe.
 */
final class LoginThrottleSubscriber implements EventSubscriberInterface
{
    private const SCOPE = 'login';
    private const MAX_FAILURES = 10;
    private const WINDOW_SECONDS = 900;
    private const FIREWALL = 'login';

    public function __construct(private readonly AttemptLimiter $limiter)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Avant la vérification du mot de passe : inutile de le tester si le compte est bloqué.
            CheckPassportEvent::class => ['onCheckPassport', 2048],
            LoginFailureEvent::class => 'onFailure',
            LoginSuccessEvent::class => 'onSuccess',
        ];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $email = $event->getPassport()->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class)?->getUserIdentifier();
        if ($this->isLoginRoute($event->getAuthenticator()) && is_string($email) && $this->limiter->isBlocked(self::SCOPE, $email, self::MAX_FAILURES)) {
            throw new CustomUserMessageAuthenticationException(AttemptLimiter::MESSAGE);
        }
    }

    public function onFailure(LoginFailureEvent $event): void
    {
        $email = $this->emailOf($event->getRequest());
        if ($event->getFirewallName() === self::FIREWALL && $email !== null) {
            $this->limiter->recordFailure(self::SCOPE, $email, self::WINDOW_SECONDS);
        }
    }

    public function onSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() === self::FIREWALL) {
            $this->limiter->clear(self::SCOPE, $event->getUser()->getUserIdentifier());
        }
    }

    /** Seule la connexion par e-mail et mot de passe est limitée (pas le JWT des autres routes). */
    private function isLoginRoute(object $authenticator): bool
    {
        return str_contains($authenticator::class, 'JsonLogin');
    }

    private function emailOf(Request $request): ?string
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) && is_string($data['email'] ?? null) && $data['email'] !== '' ? $data['email'] : null;
    }
}
