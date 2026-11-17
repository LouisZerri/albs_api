<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\DiscoveryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Onglet « Découvrir » de l'app : parcours thématiques et stations fantômes. */
final class DiscoveryController extends ApiController
{
    public function __construct(private readonly DiscoveryService $discovery)
    {
    }

    /** Parcours thématiques, avec l'avancement de l'utilisateur sur chacun. */
    #[Route('/api/me/journeys', name: 'api_me_journeys', methods: ['GET'])]
    public function journeys(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->discovery->journeys($user));
    }

    /** Stations fantômes, débloquées ou non selon les stations visitées. */
    #[Route('/api/me/ghost-stations', name: 'api_me_ghost_stations', methods: ['GET'])]
    public function ghostStations(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->discovery->ghostStations($user));
    }
}
