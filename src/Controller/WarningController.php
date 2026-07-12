<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Warning;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class WarningController extends ApiController
{
    /**
     * Avertissements de modération du compte courant (plus récents d'abord).
     */
    #[Route('/api/me/warnings', name: 'api_me_warnings', methods: ['GET'])]
    public function list(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $warnings = $em->getRepository(Warning::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
        );

        $data = array_map(static fn (Warning $w) => [
            'id' => $w->getId(),
            'reason' => $w->getReason(),
            'createdAt' => $w->getCreatedAt()?->format(\DATE_ATOM),
            'acknowledgedAt' => $w->getAcknowledgedAt()?->format(\DATE_ATOM),
        ], $warnings);

        return $this->json($data);
    }

    /**
     * Marque un avertissement comme lu (accusé de réception).
     */
    #[Route('/api/me/warnings/{id}/acknowledge', name: 'api_me_warning_ack', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function acknowledge(int $id, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $warning = $em->getRepository(Warning::class)->find($id);
        if ($warning === null || $warning->getUser()?->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Avertissement introuvable.');
        }

        if ($warning->getAcknowledgedAt() === null) {
            $warning->setAcknowledgedAt(new \DateTimeImmutable());
            $em->flush();
        }

        return $this->json(['message' => 'Avertissement lu.']);
    }
}
