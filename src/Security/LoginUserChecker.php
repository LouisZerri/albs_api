<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Contrôles de `POST /api/login` : ceux de tous les pare-feu (banni, supprimé), plus l'adresse
 * e-mail confirmée — même règle que le site.
 *
 * L'adresse n'est exigée QU'À la connexion : un changement d'adresse depuis le profil repasse le
 * compte en « non confirmé » sans fermer la session en cours.
 */
final class LoginUserChecker implements UserCheckerInterface
{
    public function __construct(private readonly AccountStatusChecker $accountStatus)
    {
    }

    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->accountStatus->checkPreAuth($user, $token);
    }

    /** Après le mot de passe : sinon on révélerait à n'importe qui qu'un compte existe et attend sa confirmation. */
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && !$user->isEmailVerified()) {
            throw new EmailNotVerifiedException();
        }
    }
}
