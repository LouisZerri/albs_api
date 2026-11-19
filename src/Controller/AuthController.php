<?php

namespace App\Controller;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\LoginThrottleSubscriber;
use App\Service\AccountMailer;
use App\Service\AttemptLimiter;
use App\Service\SessionRevoker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends ApiController
{
    private const MAX_CODE_FAILURES = 5;
    /** Durée du blocage : celle de la validité d'un code de réinitialisation. */
    private const CODE_WINDOW_SECONDS = 900;
    /** Nouveaux codes de vérification demandés sans être connecté, par adresse et par fenêtre. */
    private const MAX_RESENDS = 3;

    /**
     * Vérifie l'adresse email à partir du code reçu par mail. Body : { email, code }.
     */
    #[Route('/api/verify-email', name: 'api_verify_email', methods: ['POST'])]
    public function verifyEmail(Request $request, UserRepository $users, EntityManagerInterface $em, AttemptLimiter $limiter): JsonResponse
    {
        $data = $this->payload($request);
        $email = trim($this->requireString($data, 'email'));
        $code = trim($this->requireString($data, 'code'));

        $this->denyIfTooManyAttempts($limiter, 'verify-email', $email);

        $user = $users->findOneBy(['email' => $email]);
        if ($user === null || !$user->isEmailVerificationTokenValid() || !hash_equals((string) $user->getEmailVerificationToken(), $code)) {
            $limiter->recordFailure('verify-email', $email, self::CODE_WINDOW_SECONDS);
            throw new BadRequestHttpException('Code invalide ou expiré.');
        }

        $user->verifyEmail();
        $em->flush();
        $limiter->clear('verify-email', $email);

        return $this->json(['message' => 'Adresse email vérifiée.']);
    }

    /**
     * Renvoie un code de vérification, sans être connecté (la connexion est refusée tant que
     * l'adresse n'est pas confirmée). Body : { email }. Réponse neutre, envois limités par adresse.
     */
    #[Route('/api/resend-verification', name: 'api_resend_verification', methods: ['POST'])]
    public function resendVerification(Request $request, UserRepository $users, AccountMailer $mailer, EntityManagerInterface $em, AttemptLimiter $limiter, LoggerInterface $logger): JsonResponse
    {
        $email = trim((string) ($this->payload($request)['email'] ?? ''));

        if ($email !== '') {
            // Chaque demande compte, que le compte existe ou non : la réponse ne révèle rien.
            if ($limiter->isBlocked('resend-verification', $email, self::MAX_RESENDS)) {
                throw new TooManyRequestsHttpException(self::CODE_WINDOW_SECONDS, AttemptLimiter::MESSAGE);
            }
            $limiter->recordFailure('resend-verification', $email, self::CODE_WINDOW_SECONDS);

            $user = $users->findOneBy(['email' => $email]);
            if ($user !== null && !$user->isDeleted() && !$user->isBanned() && !$user->isEmailVerified()) {
                $user->generateEmailVerificationToken();
                $em->flush();
                $limiter->clear('verify-email', $email);
                try {
                    $mailer->sendVerification($user);
                } catch (\Throwable $e) {
                    $logger->error('Envoi email de vérification échoué : ' . $e->getMessage());
                }
            }
        }

        return $this->json(['message' => 'Si un compte attend sa confirmation, un nouveau code vient d\'être envoyé.']);
    }

    /**
     * Demande de réinitialisation. Body : { email }. Réponse volontairement neutre (pas de fuite).
     */
    #[Route('/api/forgot-password', name: 'api_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request, UserRepository $users, AccountMailer $mailer, EntityManagerInterface $em, LoggerInterface $logger, AttemptLimiter $limiter): JsonResponse
    {
        $email = trim((string) ($this->payload($request)['email'] ?? ''));

        if ($email !== '') {
            // Chaque demande remplace le code précédent et envoie un e-mail : sans plafond, on pourrait
            // inonder une boîte ou empêcher quelqu'un de réinitialiser. Compté que le compte existe ou non.
            if ($limiter->isBlocked('forgot-password', $email, self::MAX_RESENDS)) {
                throw new TooManyRequestsHttpException(self::CODE_WINDOW_SECONDS, AttemptLimiter::MESSAGE);
            }
            $limiter->recordFailure('forgot-password', $email, self::CODE_WINDOW_SECONDS);

            $user = $users->findOneBy(['email' => $email]);
            if ($user !== null && !$user->isDeleted()) {
                $user->generatePasswordResetToken();
                $em->flush();
                try {
                    $mailer->sendPasswordReset($user);
                } catch (\Throwable $e) {
                    $logger->error('Envoi email reset échoué : ' . $e->getMessage());
                }
            }
        }

        return $this->json(['message' => 'Si un compte existe pour cet email, un code de réinitialisation vient d\'être envoyé.']);
    }

    /**
     * Réinitialise le mot de passe. Body : { email, code, password }.
     * Vérification par email + code (le code court est scoppé à l'utilisateur).
     */
    #[Route('/api/reset-password', name: 'api_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request, UserRepository $users, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, AttemptLimiter $limiter, SessionRevoker $sessions): JsonResponse
    {
        $data = $this->payload($request);
        $email = trim($this->requireString($data, 'email'));
        $code = trim($this->requireString($data, 'code'));
        $password = (string) ($data['password'] ?? '');

        if (strlen($password) < 8) {
            throw new BadRequestHttpException('Le mot de passe doit faire au moins 8 caractères.');
        }

        $this->denyIfTooManyAttempts($limiter, 'reset-password', $email);

        $user = $users->findOneBy(['email' => $email]);
        if ($user === null || !$user->isPasswordResetTokenValid() || !hash_equals((string) $user->getPasswordResetToken(), $code)) {
            $limiter->recordFailure('reset-password', $email, self::CODE_WINDOW_SECONDS);
            throw new BadRequestHttpException('Code invalide ou expiré.');
        }

        $user->resetPassword($hasher->hashPassword($user, $password));
        // Mot de passe oublié = on ne sait pas qui a les sessions ouvertes : on les ferme toutes.
        $em->createQuery('DELETE FROM '.RefreshToken::class.' t WHERE t.username = :email')->execute(['email' => $user->getEmail()]);
        $em->flush();
        // … et les jetons d'accès déjà délivrés (valables 1 h) cessent de l'être tout de suite.
        $sessions->revokeAll((string) $user->getEmail());
        $limiter->clear('reset-password', $email);
        $limiter->clear('forgot-password', $email);
        $limiter->clear(LoginThrottleSubscriber::SCOPE_ACCOUNT, $email);
        $limiter->clear(LoginThrottleSubscriber::SCOPE, LoginThrottleSubscriber::pair($email, $request->getClientIp()));

        return $this->json(['message' => 'Mot de passe réinitialisé.']);
    }

    /**
     * Marque l'onboarding mobile comme terminé pour l'utilisateur connecté.
     */
    #[Route('/api/me/complete-onboarding', name: 'api_complete_onboarding', methods: ['POST'])]
    public function completeOnboarding(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        $user->completeOnboarding();
        $em->flush();

        return $this->json($user, context: ['groups' => ['user:read']]);
    }

    /**
     * Un code à 6 chiffres se devine par essais successifs : au-delà de quelques échecs pour une
     * même adresse, on refuse (429) jusqu'à la fin de la fenêtre, même avec le bon code.
     */
    private function denyIfTooManyAttempts(AttemptLimiter $limiter, string $scope, string $email): void
    {
        if ($limiter->isBlocked($scope, $email, self::MAX_CODE_FAILURES)) {
            throw new TooManyRequestsHttpException(self::CODE_WINDOW_SECONDS, AttemptLimiter::MESSAGE);
        }
    }
}
