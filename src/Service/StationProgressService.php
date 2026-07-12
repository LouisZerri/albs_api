<?php

namespace App\Service;

use App\Entity\Station;
use App\Entity\User;
use App\Entity\UserStation;
use App\Repository\StationRepository;
use App\Repository\UserStationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Progression d'un utilisateur sur les stations.
 *
 * Règle du « hub » : une station physique = tous les quais de même nom.
 *  - « descendu » (stopped) est une propriété de la STATION PHYSIQUE → propagé à tous les quais.
 *  - « passé » (passed) est propre à la LIGNE courante → jamais propagé.
 *
 * (Regroupement par nom, cohérent avec le web. La desserte directionnelle — boucle ligne 10 —
 *  nécessitera les patterns GTFS, prévu en v2.)
 */
final class StationProgressService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserStationRepository $userStations,
        private readonly StationRepository $stations,
    ) {
    }

    public function setStatus(User $user, Station $station, bool $passed, bool $stopped): UserStation
    {
        // Station courante : passé (par ligne) + descendu.
        $entry = $this->upsert($user, $station, $passed, $stopped);

        // Hub : on propage uniquement « descendu » aux autres quais (leur « passé » est conservé).
        foreach ($this->stations->findSiblings($station) as $sibling) {
            $this->upsert($user, $sibling, null, $stopped);
        }

        $this->em->flush();

        return $entry;
    }

    /**
     * Upsert d'une ligne de progression.
     * $passed = null → on conserve la valeur existante (utilisé pour les quais voisins).
     * Si l'état final est « ni passé ni descendu » → la ligne est supprimée (station « à faire »).
     */
    private function upsert(User $user, Station $station, ?bool $passed, bool $stopped): UserStation
    {
        $entry = $this->userStations->findOneBy(['user' => $user, 'station' => $station])
            ?? (new UserStation())->setUser($user)->setStation($station)->setPassed(false)->setStopped(false);

        $finalPassed = $passed ?? ($entry->isPassed() ?? false);

        if (!$finalPassed && !$stopped) {
            $entry->setPassed(false)->setStopped(false);
            if ($entry->getId() !== null) {
                $this->em->remove($entry);
            }

            return $entry;
        }

        $now = new \DateTimeImmutable();
        if ($finalPassed && $entry->getFirstPassedAt() === null) {
            $entry->setFirstPassedAt($now);
        }
        if ($stopped && $entry->getFirstStoppedAt() === null) {
            $entry->setFirstStoppedAt($now);
        }

        $entry->setPassed($finalPassed)->setStopped($stopped)->setUpdatedAt($now);
        $this->em->persist($entry);

        return $entry;
    }
}
