<?php

namespace App\Security;

use App\Service\AttemptLimiter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Ralentit les essais de mot de passe sur `POST /api/login`.
 *
 * Deux compteurs d'échecs :
 *  - par couple adresse e-mail + adresse IP (10) : celui qui devine est bloqué, lui seul — il ne
 *    peut pas empêcher le vrai propriétaire, qui se connecte d'ailleurs, d'entrer ;
 *  - par adresse e-mail seule, bien plus haut (50) : filet contre des essais répartis sur beaucoup
 *    d'adresses IP.
 */
final class LoginThrottleSubscriber implements EventSubscriberInterface
{
    public const SCOPE = 'login';
    public const SCOPE_ACCOUNT = 'login-account';
    private const MAX_FAILURES = 10;
    private const MAX_ACCOUNT_FAILURES = 50;
    private const WINDOW_SECONDS = 900;
    private const FIREWALL = 'login';

    public function __construct(
        private readonly AttemptLimiter $limiter,
        private readonly RequestStack $requests,
    ) {
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

    /** Sujet du compteur « cette adresse e-mail, depuis cette adresse IP ». */
    public static function pair(string $email, ?string $ip): string
    {
        return mb_strtolower(trim($email)).'|'.($ip ?? '');
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $email = $event->getPassport()->getBadge(UserBadge::class)?->getUserIdentifier();
        if (!$this->isLoginRoute($event->getAuthenticator()) || !is_string($email)) {
            return;
        }

        $ip = $this->requests->getMainRequest()?->getClientIp();
        if (
            $this->limiter->isBlocked(self::SCOPE, self::pair($email, $ip), self::MAX_FAILURES)
            || $this->limiter->isBlocked(self::SCOPE_ACCOUNT, $email, self::MAX_ACCOUNT_FAILURES)
        ) {
            throw new CustomUserMessageAuthenticationException(AttemptLimiter::MESSAGE);
        }
    }

    public function onFailure(LoginFailureEvent $event): void
    {
        // Bon mot de passe mais adresse non confirmée : ce n'est pas un essai de mot de passe raté.
        if ($event->getException() instanceof EmailNotVerifiedException) {
            return;
        }

        $email = $this->emailOf($event->getRequest());
        if ($event->getFirewallName() === self::FIREWALL && $email !== null) {
            $this->limiter->recordFailure(self::SCOPE, self::pair($email, $event->getRequest()->getClientIp()), self::WINDOW_SECONDS);
            $this->limiter->recordFailure(self::SCOPE_ACCOUNT, $email, self::WINDOW_SECONDS);
        }
    }

    public function onSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() === self::FIREWALL) {
            $email = $event->getUser()->getUserIdentifier();
            $this->limiter->clear(self::SCOPE, self::pair($email, $event->getRequest()->getClientIp()));
            $this->limiter->clear(self::SCOPE_ACCOUNT, $email);
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
