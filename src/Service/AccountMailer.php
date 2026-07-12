<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Emails transactionnels du compte (vérification, réinitialisation de mot de passe).
 * Rendu via les templates Twig de templates/emails/ (HTML + fallback texte).
 */
final class AccountMailer
{
    private const FROM = 'À la bonne station <no-reply@alabonnestation.fr>';

    public function __construct(
        private readonly MailerInterface $mailer,
    ) {
    }

    public function sendVerification(User $user): void
    {
        $this->mailer->send(
            $this->email((string) $user->getEmail(), 'Confirme ton adresse email', 'verification')
                ->context([
                    'username' => (string) $user->getUsername(),
                    'code' => (string) $user->getEmailVerificationToken(),
                ])
        );
    }

    public function sendPasswordReset(User $user): void
    {
        $this->mailer->send(
            $this->email((string) $user->getEmail(), 'Ton code de réinitialisation', 'password_reset')
                ->context(['code' => (string) $user->getPasswordResetToken()])
        );
    }

    /** Prépare un email templaté (HTML + texte) à partir du nom de base du template. */
    private function email(string $to, string $subject, string $template): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->from(self::FROM)
            ->to($to)
            ->subject($subject)
            ->htmlTemplate("emails/{$template}.html.twig")
            ->textTemplate("emails/{$template}.txt.twig");
    }
}
