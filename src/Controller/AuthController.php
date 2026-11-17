<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AccountMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends ApiController
{
    /**
     * Vérifie l'adresse email à partir du code reçu par mail. Body : { email, code }.
     */
    #[Route('/api/verify-email', name: 'api_verify_email', methods: ['POST'])]
    public function verifyEmail(Request $request, UserRepository $users, EntityManagerInterface $em): JsonResponse
    {
        $data = $this->payload($request);
        $email = trim($this->requireString($data, 'email'));
        $code = trim($this->requireString($data, 'code'));

        $user = $users->findOneBy(['email' => $email]);
        if ($user === null || !$user->isEmailVerificationTokenValid() || !hash_equals((string) $user->getEmailVerificationToken(), $code)) {
            throw new BadRequestHttpException('Code invalide ou expiré.');
        }

        $user->verifyEmail();
        $em->flush();

        return $this->json(['message' => 'Adresse email vérifiée.']);
    }

    /**
     * Demande de réinitialisation. Body : { email }. Réponse volontairement neutre (pas de fuite).
     */
    #[Route('/api/forgot-password', name: 'api_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request, UserRepository $users, AccountMailer $mailer, EntityManagerInterface $em, LoggerInterface $logger): JsonResponse
    {
        $email = trim((string) ($this->payload($request)['email'] ?? ''));

        if ($email !== '') {
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
    public function resetPassword(Request $request, UserRepository $users, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): JsonResponse
    {
        $data = $this->payload($request);
        $email = trim($this->requireString($data, 'email'));
        $code = trim($this->requireString($data, 'code'));
        $password = (string) ($data['password'] ?? '');

        if (strlen($password) < 8) {
            throw new BadRequestHttpException('Le mot de passe doit faire au moins 8 caractères.');
        }

        $user = $users->findOneBy(['email' => $email]);
        if ($user === null || !$user->isPasswordResetTokenValid() || !hash_equals((string) $user->getPasswordResetToken(), $code)) {
            throw new BadRequestHttpException('Code invalide ou expiré.');
        }

        $user->resetPassword($hasher->hashPassword($user, $password));
        $em->flush();

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
}
