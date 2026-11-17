<?php

namespace App\Controller;

use App\Entity\Bonplan;
use App\Entity\Station;
use App\Entity\User;
use App\Service\BonplanService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class BonplanController extends ApiController
{
    public function __construct(private readonly BonplanService $bonplans)
    {
    }

    /**
     * Stations ayant au moins un bon plan approuvé (+ ligne + nombre) : accueil de l'onglet.
     */
    #[Route('/api/bonplans/stations', name: 'api_bonplan_stations', methods: ['GET'])]
    public function stations(): JsonResponse
    {
        return $this->json($this->bonplans->stationsWithBonplans());
    }

    /**
     * Écran d'une station : { station, bonplans } (approuvés, + isFavorite pour l'utilisateur courant).
     */
    #[Route('/api/stations/{id}/bonplans', name: 'api_station_bonplans', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function forStation(Station $station, #[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->bonplans->forStation($station, $user));
    }

    /**
     * Soumettre un bon plan pour une station. Body : { title, description?, category?, address?, latitude?, longitude? }.
     * Créé en attente de modération (web).
     */
    #[Route('/api/stations/{id}/bonplans', name: 'api_station_bonplan_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function submit(Station $station, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $payload = $this->payload($request);
        $this->requireString($payload, 'title');

        $bonplan = $this->bonplans->submit($user, $station, $payload);

        return $this->json([
            'id' => $bonplan->getId(),
            'message' => 'Bon plan soumis. Il apparaîtra après modération.',
        ], Response::HTTP_CREATED);
    }

    /**
     * Les bons plans favoris de l'utilisateur.
     */
    #[Route('/api/me/favorites', name: 'api_me_favorites', methods: ['GET'])]
    public function favorites(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->bonplans->favoritesOf($user));
    }

    #[Route('/api/bonplans/{id}/favorite', name: 'api_bonplan_favorite_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addFavorite(Bonplan $bonplan, #[CurrentUser] User $user): JsonResponse
    {
        $this->bonplans->addFavorite($user, $bonplan);

        return $this->json(['favorite' => true]);
    }

    #[Route('/api/bonplans/{id}/favorite', name: 'api_bonplan_favorite_remove', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function removeFavorite(Bonplan $bonplan, #[CurrentUser] User $user): JsonResponse
    {
        $this->bonplans->removeFavorite($user, $bonplan);

        return $this->json(['favorite' => false]);
    }
}
