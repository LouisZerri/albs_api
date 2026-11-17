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
    /** Durée maximale pendant laquelle un cochage fait hors ligne garde son heure d'origine. */
    private const MAX_OFFLINE_DAYS = 30;
    /** Décochée moins longtemps que ça après sa première fois, une station est considérée cochée par erreur. */
    private const MISTAKE_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserStationRepository $userStations,
        private readonly StationRepository $stations,
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $at Moment RÉEL du cochage, quand il a été fait hors ligne et
     *                                    n'arrive qu'à la reconnexion (null = maintenant)
     */
    public function setStatus(
        User $user,
        Station $station,
        bool $passed,
        bool $stopped,
        ?\DateTimeImmutable $at = null,
    ): UserStation {
        $at = $this->sanitizeActionTime($at);

        // Station courante : passé (par ligne) + descendu.
        $entry = $this->upsert($user, $station, $passed, $stopped, $at);

        // Hub : on propage uniquement « descendu » aux autres quais (leur « passé » est conservé).
        foreach ($this->stations->findSiblings($station) as $sibling) {
            $this->upsert($user, $sibling, null, $stopped, $at);
        }

        $this->em->flush();

        return $entry;
    }

    /**
     * Upsert d'une ligne de progression.
     * $passed = null → on conserve la valeur existante (utilisé pour les quais voisins).
     *
     * Une station décochée GARDE sa ligne en base (ni passé ni descendu), comme sur le web. Cela sert à :
     *  - conserver la date de première visite : décocher puis recocher ne « rajeunit » pas une
     *    vieille station (sinon on gonflerait les défis de la semaine, et une fausse manip
     *    effacerait la vraie date de l'historique) ;
     *  - appliquer « le plus récent gagne » même après un décochage.
     */
    private function upsert(
        User $user,
        Station $station,
        ?bool $passed,
        bool $stopped,
        \DateTimeImmutable $at,
    ): UserStation {
        $entry = $this->userStations->findOneBy(['user' => $user, 'station' => $station])
            ?? (new UserStation())->setUser($user)->setStation($station)->setPassed(false)->setStopped(false);
        $isNew = $entry->getId() === null;

        // Le plus récent gagne : un cochage hors ligne qui arrive en retard ne doit pas écraser un
        // changement fait ENTRE-TEMPS ailleurs (site web, autre téléphone).
        if (!$isNew && $entry->getUpdatedAt() !== null && $entry->getUpdatedAt() > $at) {
            return $entry;
        }

        $finalPassed = $passed ?? ($entry->isPassed() ?? false);

        // Jamais cochée et toujours « à faire » : rien à retenir.
        if ($isNew && !$finalPassed && !$stopped) {
            return $entry;
        }

        $entry->setFirstPassedAt(
            $this->firstDate($entry->getFirstPassedAt(), (bool) $entry->isPassed(), $finalPassed, $entry->getUpdatedAt(), $at, $isNew),
        );
        $entry->setFirstStoppedAt(
            $this->firstDate($entry->getFirstStoppedAt(), (bool) $entry->isStopped(), $stopped, $entry->getUpdatedAt(), $at, $isNew),
        );

        $entry->setPassed($finalPassed)->setStopped($stopped)->setUpdatedAt($at);
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * Date de « première fois » d'un état (passé ou descendu) après un changement.
     *
     * - Coché pour la première fois → l'heure du cochage.
     * - Recoché plus tard → la date d'origine est conservée.
     * - Décoché dans les minutes qui suivent la première fois → c'était une fausse manip, la date
     *   est oubliée (la vraie visite, un autre jour, aura sa vraie date).
     * - Décoché plus tard → la date reste, au cas où la station serait recochée.
     */
    private function firstDate(
        ?\DateTimeImmutable $first,
        bool $was,
        bool $is,
        ?\DateTimeImmutable $lastChange,
        \DateTimeImmutable $at,
        bool $isNew,
    ): ?\DateTimeImmutable {
        // Anciennes données cochées sans date : la dernière modification en tient lieu.
        if ($was && $first === null && !$isNew) {
            $first = $lastChange;
        }

        if ($is) {
            return $first ?? $at;
        }

        $uncheckedRightAway = $was && $first !== null
            && $at->getTimestamp() - $first->getTimestamp() < self::MISTAKE_WINDOW_SECONDS;

        return $uncheckedRightAway ? null : $first;
    }

    /**
     * Heure d'un cochage transmise par l'app : acceptée seulement si elle est plausible (ni dans le
     * futur, ni plus vieille que la fenêtre de synchronisation). Sinon on prend l'heure du serveur.
     */
    private function sanitizeActionTime(?\DateTimeImmutable $at): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        if ($at === null || $at > $now || $at < $now->modify('-'.self::MAX_OFFLINE_DAYS.' days')) {
            return $now;
        }

        // Même fuseau que le reste de la base (Europe/Paris), quel que soit celui envoyé par l'app.
        return $at->setTimezone($now->getTimezone());
    }
}
