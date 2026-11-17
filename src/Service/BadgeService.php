<?php

namespace App\Service;

use App\I18n\Translator;
use App\Entity\Badge;
use App\Entity\User;
use App\Repository\BadgeRepository;
use App\Repository\LineRepository;
use App\Repository\StationRepository;
use App\Repository\UserStationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Attribution et progression des badges (même logique que le web, sans le translator :
 * les noms sont stockés en français en base).
 *
 * Les critères (JSON `badge.criteria`) : stopped, passed, line_complete, all_stations,
 * account_created, night_visit, early_visit, daily_marathon, line_passed_same.
 */
final class BadgeService
{
    /**
     * Descriptions de badges en tutoiement pour l'app mobile (clé = nom du badge).
     * La base partagée reste en vouvoiement pour le web ; on ne l'altère pas.
     */
    private const DESCRIPTIONS_TU = [
        'Parisien en herbe' => 'Félicitations ! Tu as visité ta première station.',
        'Touriste averti' => 'Tu es passé par 10 stations différentes.',
        'Habitué du métro' => 'Tu as visité 5 stations.',
        'Vrai Parisien' => 'Tu as visité 25 stations. Tu connais ton chemin !',
        'Rat des quais' => 'Tu es passé par 50 stations. Le métro n\'a plus de secrets pour toi !',
        'Explorateur urbain' => 'Tu as visité 50 stations différentes.',
        'Maître de ligne' => 'Tu as visité toutes les stations d\'une ligne.',
        'Collectionneur de lignes' => 'Tu as complété 3 lignes entières.',
        'Seigneur du métro' => 'Tu as complété 5 lignes. Respect !',
        'Globe-trotter parisien' => 'Tu as visité 100 stations. Impressionnant !',
        'Légende du métro' => 'Tu as visité toutes les stations du métro parisien !',
        'Oiseau de nuit' => 'Tu as visité une station entre 22h et 6h du matin.',
        'Lève-tôt' => 'Tu as visité une station entre 6h et 9h du matin.',
        'Jogger du métro' => 'Tu as visité 10 stations en une seule journée.',
        'Marathonien du métro' => 'Tu as visité 20 stations en une seule journée. Impressionnant !',
        'Ultra-marathonien' => 'Tu as visité 30 stations en une seule journée. Tu es exceptionnel !',
        'Fidèle de la ligne' => 'Tu es passé par 20 stations de la même ligne.',
        'Nouveau départ' => 'Bienvenue ! Tu viens de créer ton compte.',
    ];

    public function __construct(
        private readonly BadgeRepository $badges,
        private readonly UserStationRepository $userStations,
        private readonly LineRepository $lines,
        private readonly StationRepository $stations,
        private readonly EntityManagerInterface $em,
        private readonly Translator $translator,
    ) {
    }

    /**
     * Attribue les nouveaux badges débloqués et les décrit (pour la célébration côté app).
     *
     * @return list<array{id:int, name:string, icon:string}>
     */
    public function awardAndDescribe(User $user): array
    {
        return array_map(
            fn (Badge $b) => ['id' => $b->getId(), 'name' => $this->translator->t($b->getName()), 'icon' => $b->getIcon()],
            $this->checkAndAwardBadges($user),
        );
    }

    /**
     * Vérifie et attribue les badges nouvellement débloqués.
     *
     * @return list<Badge>
     */
    public function checkAndAwardBadges(User $user): array
    {
        $ctx = $this->buildContext($user);
        $owned = $user->getBadges();

        $new = [];
        foreach ($this->badges->findAll() as $badge) {
            if ($owned->contains($badge)) {
                continue;
            }
            if ($this->meetsCriteria($badge, $ctx)) {
                $user->addBadge($badge);
                $new[] = $badge;
            }
        }

        if ($new !== []) {
            $this->em->flush();
        }

        return $new;
    }

    /**
     * Tous les badges avec leur statut (débloqué + progression %) pour l'écran Profil.
     *
     * @return list<array{id:int, name:string, description:?string, icon:string, type:?string, earned:bool, progress:int, current:?int, target:?int}>
     */
    public function statusFor(User $user): array
    {
        $ctx = $this->buildContext($user);

        $owned = [];
        foreach ($user->getBadges() as $b) {
            $owned[$b->getId()] = true;
        }

        $out = [];
        foreach ($this->badges->findAll() as $badge) {
            $isOwned = isset($owned[$badge->getId()]);
            $counter = $isOwned ? null : $this->counter($badge, $ctx);
            $out[] = [
                'id' => $badge->getId(),
                'name' => $this->translator->t($badge->getName()),
                // L'app mobile tutoie (le web vouvoie) → on sert une version « tu »
                // sans toucher la base partagée. Repli sur la description DB si absente.
                'description' => $this->translator->t(self::DESCRIPTIONS_TU[$badge->getName()] ?? $badge->getDescription()),
                'icon' => $badge->getIcon(),
                'type' => $badge->getType(),
                'earned' => $isOwned,
                'progress' => $isOwned ? 100 : $this->progress($badge, $ctx),
                // Compteur lisible (« 12 / 20 ») pour les badges à débloquer ; null si sans compteur.
                'current' => $counter !== null ? min($counter[0], $counter[1]) : null,
                'target' => $counter[1] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Agrège en une passe tout ce dont les critères ont besoin (évite le N+1).
     *
     * @return array{totalPassed:int, totalStopped:int, totalStations:int, completedLines:int, maxPassedOnLine:int, maxInOneDay:int, hasNight:bool, hasEarly:bool}
     */
    private function buildContext(User $user): array
    {
        $byStationId = [];
        $totalPassed = 0;
        $totalStopped = 0;
        $stationsByDay = [];
        $hasNight = false;
        $hasEarly = false;

        foreach ($this->userStations->findByUserWithStations($user) as $us) {
            $byStationId[$us->getStation()->getId()] = $us;
            if ($us->isPassed()) {
                $totalPassed++;
            }
            if ($us->isStopped()) {
                $totalStopped++;
            }

            // Seulement si la station est encore cochée : une station décochée garde sa date en base.
            $stoppedAt = $us->isStopped() ? $us->getFirstStoppedAt() : null;
            if ($stoppedAt !== null) {
                $hour = (int) $stoppedAt->format('H');
                if (($hour >= 22 && $hour <= 23) || ($hour >= 0 && $hour <= 5)) {
                    $hasNight = true;
                }
                if ($hour >= 6 && $hour <= 8) {
                    $hasEarly = true;
                }
                $day = $stoppedAt->format('Y-m-d');
                $stationsByDay[$day] = ($stationsByDay[$day] ?? 0) + 1;
            }
        }

        $completedLines = 0;
        $maxPassedOnLine = 0;
        foreach ($this->lines->findAllWithStations() as $line) {
            $total = count($line->getStations());
            $passed = 0;
            $stopped = 0;
            foreach ($line->getStations() as $station) {
                $us = $byStationId[$station->getId()] ?? null;
                if ($us === null) {
                    continue;
                }
                if ($us->isPassed()) {
                    $passed++;
                }
                if ($us->isStopped()) {
                    $stopped++;
                }
            }
            if ($total > 0 && $stopped === $total) {
                $completedLines++;
            }
            $maxPassedOnLine = max($maxPassedOnLine, $passed);
        }

        return [
            'totalPassed' => $totalPassed,
            'totalStopped' => $totalStopped,
            'totalStations' => $this->stations->count([]),
            'completedLines' => $completedLines,
            'maxPassedOnLine' => $maxPassedOnLine,
            'maxInOneDay' => $stationsByDay !== [] ? max($stationsByDay) : 0,
            'hasNight' => $hasNight,
            'hasEarly' => $hasEarly,
        ];
    }

    /**
     * @param array{totalPassed:int, totalStopped:int, totalStations:int, completedLines:int, maxPassedOnLine:int, maxInOneDay:int, hasNight:bool, hasEarly:bool} $c
     */
    private function meetsCriteria(Badge $badge, array $c): bool
    {
        $cr = $badge->getCriteria();

        if (isset($cr['stopped']) && $c['totalStopped'] >= $cr['stopped']) {
            return true;
        }
        if (isset($cr['passed']) && $c['totalPassed'] >= $cr['passed']) {
            return true;
        }
        if (isset($cr['line_complete']) && $c['completedLines'] >= $cr['line_complete']) {
            return true;
        }
        if (!empty($cr['all_stations']) && $c['totalStopped'] >= $c['totalStations']) {
            return true;
        }
        if (!empty($cr['account_created'])) {
            return true;
        }
        if (!empty($cr['night_visit']) && $c['hasNight']) {
            return true;
        }
        if (!empty($cr['early_visit']) && $c['hasEarly']) {
            return true;
        }
        if (isset($cr['daily_marathon']) && $c['maxInOneDay'] >= $cr['daily_marathon']) {
            return true;
        }
        if (isset($cr['line_passed_same']) && $c['maxPassedOnLine'] >= $cr['line_passed_same']) {
            return true;
        }

        return false;
    }

    /**
     * Avancement chiffré d'un badge à compteur : [acquis, objectif] (ex. 12 stations sur 20).
     * null pour les badges « oui/non » (visite de nuit, compte créé…), qui n'ont pas de compteur.
     *
     * @param array{totalPassed:int, totalStopped:int, totalStations:int, completedLines:int, maxPassedOnLine:int, maxInOneDay:int, hasNight:bool, hasEarly:bool} $c
     *
     * @return array{int, int}|null
     */
    private function counter(Badge $badge, array $c): ?array
    {
        $cr = $badge->getCriteria();

        return match (true) {
            isset($cr['stopped']) => [$c['totalStopped'], (int) $cr['stopped']],
            isset($cr['passed']) => [$c['totalPassed'], (int) $cr['passed']],
            isset($cr['line_complete']) => [$c['completedLines'], (int) $cr['line_complete']],
            !empty($cr['all_stations']) => [$c['totalStopped'], $c['totalStations']],
            isset($cr['daily_marathon']) => [$c['maxInOneDay'], (int) $cr['daily_marathon']],
            isset($cr['line_passed_same']) => [$c['maxPassedOnLine'], (int) $cr['line_passed_same']],
            default => null,
        };
    }

    /**
     * Pourcentage vers le déblocage (0–100).
     *
     * @param array{totalPassed:int, totalStopped:int, totalStations:int, completedLines:int, maxPassedOnLine:int, maxInOneDay:int, hasNight:bool, hasEarly:bool} $c
     */
    private function progress(Badge $badge, array $c): int
    {
        $counter = $this->counter($badge, $c);
        if ($counter !== null) {
            return $this->pct($counter[0], $counter[1]);
        }

        $cr = $badge->getCriteria();
        if (!empty($cr['night_visit'])) {
            return $c['hasNight'] ? 100 : 0;
        }
        if (!empty($cr['early_visit'])) {
            return $c['hasEarly'] ? 100 : 0;
        }

        return !empty($cr['account_created']) ? 100 : 0;
    }

    private function pct(int $have, int $need): int
    {
        return $need > 0 ? (int) min(100, round($have / $need * 100)) : 0;
    }
}
