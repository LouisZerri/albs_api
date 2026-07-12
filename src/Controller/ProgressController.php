<?php

namespace App\Controller;

use App\Entity\Station;
use App\Entity\User;
use App\Service\BadgeService;
use App\Service\ProfileStatsService;
use App\Service\StationProgressService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ProgressController extends ApiController
{

    /**
     * Toute la progression de l'utilisateur courant (à cacher en local côté app pour l'offline).
     */
    #[Route('/api/me/progress', name: 'api_me_progress', methods: ['GET'])]
    public function myProgress(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($user->getUserStations(), 200, [], ['groups' => ['progress:read']]);
    }

    /**
     * Stats de progression : global (% visité) + complétion par ligne. Pour l'écran Carte / Profil.
     */
    #[Route('/api/me/stats', name: 'api_me_stats', methods: ['GET'])]
    public function stats(#[CurrentUser] User $user, ProfileStatsService $stats): JsonResponse
    {
        return $this->json($stats->forUser($user));
    }

    /**
     * Badges de l'utilisateur : chacun avec `earned` + `progress` (%). Pour l'écran Profil.
     */
    #[Route('/api/me/badges', name: 'api_me_badges', methods: ['GET'])]
    public function badges(#[CurrentUser] User $user, BadgeService $badges): JsonResponse
    {
        return $this->json($badges->statusFor($user));
    }

    /**
     * Définit l'état d'une station pour l'utilisateur courant.
     * Body : { "passed": bool, "stopped": bool }
     */
    #[Route('/api/stations/{id}/progress', name: 'api_station_progress', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function setStatus(
        Station $station,
        Request $request,
        #[CurrentUser] User $user,
        StationProgressService $progress,
        BadgeService $badges,
    ): JsonResponse {
        $payload = $this->payload($request);

        $entry = $progress->setStatus(
            $user,
            $station,
            (bool) ($payload['passed'] ?? false),
            (bool) ($payload['stopped'] ?? false),
        );

        // Attribution automatique : les badges éventuellement débloqués sont renvoyés
        // pour que l'app puisse afficher une célébration.
        $newBadges = $badges->awardAndDescribe($user);

        return $this->json(
            ['progress' => $entry, 'newBadges' => $newBadges],
            200,
            [],
            ['groups' => ['progress:read']],
        );
    }
}
