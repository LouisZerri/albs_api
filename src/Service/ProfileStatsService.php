<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\LineRepository;
use App\Repository\StationRepository;
use App\Repository\UserStationRepository;

/**
 * Statistiques de progression d'un utilisateur (pour l'écran Carte / Profil).
 * Cohérent avec le web : « visité » = station où l'on est descendu (stopped).
 *
 * v1 : comptage par quai (station row). v2 (hub) : comptage par station physique.
 */
final class ProfileStatsService
{
    public function __construct(
        private readonly UserStationRepository $userStations,
        private readonly LineRepository $lines,
        private readonly StationRepository $stations,
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
        $totalStopped = 0;
        foreach ($this->userStations->findByUserWithStations($user) as $us) {
            $byStationId[$us->getStation()->getId()] = $us;
            if ($us->isPassed()) {
                $totalPassed++;
            }
            if ($us->isStopped()) {
                $totalStopped++;
            }
        }

        $totalStations = $this->stations->count([]);

        $lines = [];
        foreach ($this->lines->findAllWithStations() as $line) {
            $stations = $line->getStations();
            $total = count($stations);
            $visited = 0;
            $passed = 0;

            foreach ($stations as $station) {
                $us = $byStationId[$station->getId()] ?? null;
                if ($us === null) {
                    continue;
                }
                if ($us->isStopped()) {
                    $visited++;
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

        return [
            'stations' => [
                'total' => $totalStations,
                'visited' => $totalStopped,
                'passed' => $totalPassed,
            ],
            'percent' => $totalStations > 0 ? (int) round($totalStopped / $totalStations * 100) : 0,
            'lines' => $lines,
        ];
    }
}
