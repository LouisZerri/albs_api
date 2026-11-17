<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\LineRepository;
use App\Repository\UserStationRepository;

/**
 * Statistiques de progression d'un utilisateur (pour l'écran Carte / Profil).
 * Cohérent avec le web : « visité » = station où l'on est descendu (stopped).
 *
 * Global : comptage par station PHYSIQUE (même nom = une station). Par ligne : comptage par quai.
 */
final class ProfileStatsService
{
    public function __construct(
        private readonly UserStationRepository $userStations,
        private readonly LineRepository $lines,
    ) {
    }

    /**
     * @return array{
     *     stations: array{total:int, visited:int, passed:int},
     *     percent: int,
     *     lines: list<array{id:int, number:string, name:string, color:string, textColor:?string, total:int, visited:int, passed:int, percent:int}>
     * }
     */
    public function forUser(User $user): array
    {
        // Map stationId => UserStation + compteurs globaux
        $byStationId = [];
        $totalPassed = 0;
        foreach ($this->userStations->findByUserWithStations($user) as $us) {
            $byStationId[$us->getStation()->getId()] = $us;
            if ($us->isPassed()) {
                $totalPassed++;
            }
        }

        // Global = stations PHYSIQUES (regroupées par nom, comme les correspondances) : Châtelet compte
        // pour une station, pas pour ses 5 quais. « Passé » reste par quai (règle : propre à la ligne).
        $allNames = [];
        $visitedNames = [];

        $lines = [];
        foreach ($this->lines->findAllWithStations() as $line) {
            $stations = $line->getStations();
            $total = count($stations);
            $visited = 0;
            $passed = 0;

            foreach ($stations as $station) {
                $allNames[$station->getName()] = true;
                $us = $byStationId[$station->getId()] ?? null;
                if ($us === null) {
                    continue;
                }
                if ($us->isStopped()) {
                    $visited++;
                    $visitedNames[$station->getName()] = true;
                }
                if ($us->isPassed()) {
                    $passed++;
                }
            }

            $lines[] = [
                'id' => $line->getId(),
                'number' => $line->getNumber(),
                'name' => $line->getName(),
                'color' => $line->getColor(),
                'textColor' => $line->getTextColor(),
                'total' => $total,
                'visited' => $visited,
                'passed' => $passed,
                'percent' => $total > 0 ? (int) round($visited / $total * 100) : 0,
            ];
        }

        $totalStations = count($allNames);
        $totalVisited = count($visitedNames);

        return [
            'stations' => [
                'total' => $totalStations,
                'visited' => $totalVisited,
                'passed' => $totalPassed,
            ],
            'percent' => $totalStations > 0 ? (int) round($totalVisited / $totalStations * 100) : 0,
            'lines' => $lines,
        ];
    }
}
