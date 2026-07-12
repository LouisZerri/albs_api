<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Base des contrôleurs API : parsing du corps (JSON ou multipart) mutualisé.
 * Les erreurs sont signalées en levant des HttpException (formatées en JSON par ApiExceptionListener).
 */
abstract class ApiController extends AbstractController
{
    /**
     * Corps de la requête : JSON ou form-data (multipart).
     *
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        if (str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')) {
            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                throw new BadRequestHttpException('Corps de requête JSON invalide.');
            }

            return $data;
        }

        return $request->request->all();
    }

    /**
     * Fichiers uploadés sous le champ `images` (0..n).
     *
     * @return UploadedFile[]
     */
    protected function uploadedImages(Request $request): array
    {
        $files = $request->files->get('images', []);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        return array_values(array_filter((array) $files));
    }

    /**
     * Récupère un champ texte requis (lève 400 si vide).
     *
     * @param array<string, mixed> $data
     */
    protected function requireString(array $data, string $key): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value === '') {
            throw new BadRequestHttpException(sprintf('Le champ « %s » est requis.', $key));
        }

        return $value;
    }

    /**
     * Vérifie que l'utilisateur courant est bien le propriétaire (auteur) de la ressource.
     */
    protected function denyUnlessOwner(?User $owner, User $current): void
    {
        if ($owner === null || $owner->getId() !== $current->getId()) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        }
    }
}
