<?php

namespace App\Controller;

use App\Entity\BonplanFavorite;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Entity\UserStation;
use App\Exception\FieldException;
use App\Repository\BadgeRepository;
use App\Repository\LineRepository;
use App\Repository\UserRepository;
use App\Service\AccountMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\String\Slugger\SluggerInterface;

final class AccountController extends ApiController
{
    private const AVATAR_DIR = 'uploads/avatars';
    private const MAX_AVATAR_BYTES = 5 * 1024 * 1024; // 5 Mo

    /**
     * Édition du profil courant : pseudo, email, et/ou mot de passe.
     * Body JSON : { username?, email?, currentPassword?, newPassword? }
     * Chaque champ est optionnel ; seuls ceux fournis sont modifiés.
     */
    #[Route('/api/me', name: 'api_me_update', methods: ['PATCH'])]
    public function update(
        Request $request,
        #[CurrentUser] User $user,
        UserRepository $users,
        LineRepository $lines,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): JsonResponse {
        $data = $this->payload($request);

        // --- Pseudo ---
        if (isset($data['username'])) {
            $username = trim((string) $data['username']);
            $len = mb_strlen($username);
            if ($len < 3 || $len > 30) {
                throw new FieldException('username', 'Le pseudo doit faire entre 3 et 30 caractères.');
            }
            $existing = $users->findOneBy(['username' => $username]);
            if ($existing !== null && $existing->getId() !== $user->getId()) {
                throw new FieldException('username', 'Ce pseudo est déjà utilisé.');
            }
            $user->setUsername($username);
        }

        // --- Email ---
        if (isset($data['email'])) {
            $email = trim((string) $data['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new FieldException('email', 'Email invalide.');
            }
            $existing = $users->findOneBy(['email' => $email]);
            if ($existing !== null && $existing->getId() !== $user->getId()) {
                throw new FieldException('email', 'Cet email est déjà utilisé.');
            }
            // Un changement d'email repasse le compte en non-vérifié (ré-incitation).
            if ($email !== $user->getEmail()) {
                // Les sessions sont rangées sous l'adresse e-mail : elles suivent la nouvelle, sinon
                // l'app ne pourrait plus renouveler la sienne et l'utilisateur serait déconnecté.
                $em->createQuery('UPDATE '.RefreshToken::class.' t SET t.username = :new WHERE t.username = :old')
                    ->execute(['new' => $email, 'old' => $user->getEmail()]);
                $user->setEmail($email)->setIsEmailVerified(false);
            }
        }

        // --- Mot de passe (nécessite le mot de passe actuel) ---
        if (!empty($data['newPassword'])) {
            $current = (string) ($data['currentPassword'] ?? '');
            if (!$hasher->isPasswordValid($user, $current)) {
                throw new FieldException('currentPassword', 'Mot de passe actuel incorrect.');
            }
            $new = (string) $data['newPassword'];
            if (mb_strlen($new) < 8) {
                throw new FieldException('newPassword', 'Le nouveau mot de passe doit faire au moins 8 caractères.');
            }
            $user->setPassword($hasher->hashPassword($user, $new));
        }

        // --- Ligne favorite (null pour retirer) ---
        if (array_key_exists('favoriteLineId', $data)) {
            $lineId = $data['favoriteLineId'];
            if ($lineId === null) {
                $user->setFavoriteLine(null);
            } else {
                $line = $lines->find((int) $lineId);
                if ($line === null) {
                    throw new BadRequestHttpException('Ligne inconnue.');
                }
                $user->setFavoriteLine($line);
            }
        }

        $em->flush();

        return $this->json($user, 200, [], ['groups' => ['user:read']]);
    }

    /**
     * Ajoute/retire un badge de la vitrine du profil (max 2). L'utilisateur doit le posséder.
     */
    #[Route('/api/me/badges/{id}/display', name: 'api_me_badge_display', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function toggleDisplayBadge(
        int $id,
        #[CurrentUser] User $user,
        BadgeRepository $badges,
        EntityManagerInterface $em,
    ): JsonResponse {
        if ($badges->find($id) === null) {
            throw new BadRequestHttpException('Badge inconnu.');
        }

        $owns = false;
        foreach ($user->getBadges() as $b) {
            if ($b->getId() === $id) {
                $owns = true;
                break;
            }
        }
        if (!$owns) {
            throw new BadRequestHttpException('Badge non débloqué.');
        }

        if (in_array($id, $user->getDisplayedBadges(), true)) {
            $user->removeDisplayedBadge($id);
        } elseif (count($user->getDisplayedBadges()) >= 2) {
            throw new BadRequestHttpException('2 badges maximum en vitrine.');
        } else {
            $user->addDisplayedBadge($id);
        }

        $em->flush();

        return $this->json($user, 200, [], ['groups' => ['user:read']]);
    }

    /**
     * Renvoie un nouveau code de vérification par email au compte courant.
     */
    #[Route('/api/me/resend-verification', name: 'api_me_resend_verification', methods: ['POST'])]
    public function resendVerification(
        #[CurrentUser] User $user,
        AccountMailer $mailer,
        EntityManagerInterface $em,
    ): JsonResponse {
        if ($user->isEmailVerified()) {
            return $this->json(['message' => 'Adresse déjà vérifiée.']);
        }

        $user->generateEmailVerificationToken();
        $em->flush();
        $mailer->sendVerification($user);

        return $this->json(['message' => 'Un nouveau code a été envoyé par email.']);
    }

    /**
     * Upload / remplacement de l'avatar. Multipart, champ fichier « avatar ».
     */
    #[Route('/api/me/avatar', name: 'api_me_avatar_upload', methods: ['POST'])]
    public function uploadAvatar(
        Request $request,
        #[CurrentUser] User $user,
        SluggerInterface $slugger,
        EntityManagerInterface $em,
    ): JsonResponse {
        $file = $request->files->get('avatar');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Aucun fichier « avatar » reçu.');
        }
        if ($file->getSize() > self::MAX_AVATAR_BYTES) {
            throw new BadRequestHttpException('Image trop lourde (max 5 Mo).');
        }
        if (!str_starts_with((string) $file->getMimeType(), 'image/')) {
            throw new BadRequestHttpException('Le fichier doit être une image.');
        }

        $dir = $this->getParameter('kernel.project_dir') . '/public/' . self::AVATAR_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new BadRequestHttpException('Impossible de créer le dossier d\'upload.');
        }

        $safe = $slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $filename = $safe . '-' . uniqid() . '.' . ($file->guessExtension() ?: 'jpg');

        try {
            $file->move($dir, $filename);
        } catch (FileException) {
            throw new BadRequestHttpException('Échec de l\'enregistrement de l\'image.');
        }

        $this->deleteAvatarFile($user->getAvatarUrl());
        $user->setAvatarUrl($filename);
        $em->flush();

        return $this->json($user, 200, [], ['groups' => ['user:read']]);
    }

    /**
     * Suppression de l'avatar (retour aux initiales).
     */
    #[Route('/api/me/avatar', name: 'api_me_avatar_delete', methods: ['DELETE'])]
    public function deleteAvatar(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $this->deleteAvatarFile($user->getAvatarUrl());
        $user->setAvatarUrl(null);
        $em->flush();

        return $this->json($user, 200, [], ['groups' => ['user:read']]);
    }

    /**
     * Suppression de compte (RGPD) par ANONYMISATION : on efface les données personnelles
     * mais on conserve le contenu communautaire (posts forum, bons plans) attribué à un
     * compte « supprimé ». Le statut `deleted` invalide de fait le JWT (AccountStatusChecker).
     *
     * Tout ce qui est PRIVÉ disparaît aussi (progression, favoris, badges, sessions) : ces données
     * n'ont plus aucun usage une fois le compte fermé. Les sessions sont rangées sous l'adresse
     * e-mail : les laisser garderait cette adresse en base.
     */
    #[Route('/api/me', name: 'api_me_delete', methods: ['DELETE'])]
    public function delete(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $id = $user->getId();
        $this->deleteAvatarFile($user->getAvatarUrl());

        // Avant d'anonymiser : les sessions sont identifiées par l'e-mail encore en place.
        $em->createQuery('DELETE FROM '.RefreshToken::class.' t WHERE t.username = :email')
            ->execute(['email' => $user->getEmail()]);
        $em->createQuery('DELETE FROM '.UserStation::class.' us WHERE us.user = :user')->execute(['user' => $user]);
        $em->createQuery('DELETE FROM '.BonplanFavorite::class.' f WHERE f.user = :user')->execute(['user' => $user]);
        foreach ($user->getBadges()->toArray() as $badge) {
            $user->removeBadge($badge);
        }

        $user
            ->setEmail('deleted-' . $id . '@albs.local')
            ->setUsername('Compte supprimé #' . $id)
            ->setPassword(bin2hex(random_bytes(16))) // hash invalide → connexion impossible
            ->setAvatarUrl(null)
            ->setFavoriteLine(null)
            ->setDisplayedBadges([])
            ->setAccountStatus('deleted')
            ->setIsEmailVerified(false)
            ->setEmailVerificationToken(null)
            ->setEmailVerificationTokenExpiresAt(null)
            ->setPasswordResetToken(null)
            ->setPasswordResetTokenExpiresAt(null);

        $em->flush();

        return $this->json(['message' => 'Compte supprimé.']);
    }

    /** Supprime physiquement le fichier avatar s'il existe. */
    private function deleteAvatarFile(?string $filename): void
    {
        if (!$filename) {
            return;
        }
        $path = $this->getParameter('kernel.project_dir') . '/public/' . self::AVATAR_DIR . '/' . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
