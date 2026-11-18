<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/** Connexion refusée : le mot de passe est bon, mais l'adresse e-mail n'a pas été confirmée. */
final class EmailNotVerifiedException extends CustomUserMessageAccountStatusException
{
    /** Repère envoyé à l'app (`reason`) pour qu'elle ouvre l'écran du code. */
    public const REASON = 'email_not_verified';

    public function __construct()
    {
        parent::__construct('Confirme ton adresse email avant de te connecter.');
    }
}
