<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\StationRepository;
use App\Repository\UserStationRepository;

/**
 * Historique des visites : chaque station PHYSIQUE où l'utilisateur est descendu, avec la date
 * de sa première visite. Sert l'écran « Mon historique » (frise, calendrier, statistiques).
 *
 * Limite connue : seule la PREMIÈRE descente est datée (`first_stopped_at`). Ce n'est pas un
 * journal : une station décochée puis recochée garde sa date d'origine.
 */
final class VisitHistoryService
{
    public function __construct(
        private readonly UserStationRepository $userStations,
        private readonly StationRepository $stations,
    ) {
    }

    /**
     * Visites, de la plus récente à la plus ancienne. Les quais d'une même station (même nom) sont
     * regroupés : descendre à une correspondance coche tous ses quais, on ne la compte qu'une fois.
     *
     * @return list<array{name: string, visitedAt: string, lines: list<array<string, mixed>>}>
     */
    public function forUser(User $user): array
    {
        $firstVisit = $this->firstVisits($user);
        arsort($firstVisit); // objets DateTime comparables : plus récent d'abord

        $linesByName = $this->stations->linesByStationName();
        $visits = [];
        foreach ($firstVisit as $name => $at) {
            $visits[] = [
                'name' => $name,
                'visitedAt' => $at->format(\DateTimeInterface::ATOM),
                'lines' => $linesByName[$name] ?? [],
            ];
        }

        return $visits;
    }

    /**
     * Date de la toute première descente à chaque station physique (nom de station => date).
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function firstVisits(User $user): array
    {
        $firstVisit = [];
        foreach ($this->userStations->findByUserWithStations($user) as $us) {
            $at = $us->getFirstStoppedAt();
            $name = $us->getStation()?->getName();
            if (!$us->isStopped() || $at === null || $name === null) {
                continue;
            }
            if (!isset($firstVisit[$name]) || $at < $firstVisit[$name]) {
                $firstVisit[$name] = $at;
            }
        }

        return $firstVisit;
    }
}
