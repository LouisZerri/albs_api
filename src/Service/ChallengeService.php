<?php

namespace App\Service;

use App\I18n\Translator;
use App\Entity\User;

/**
 * Défis en cours : des objectifs courts (semaine, mois, ligne presque finie) pour donner une
 * raison de revenir. Entièrement DÉDUITS des dates de visite : rien n'est enregistré, et un défi
 * repart de zéro tout seul au changement de semaine ou de mois (heure de Paris).
 */
final class ChallengeService
{
    private const WEEKLY_TARGET = 3;
    private const MONTHLY_TARGET = 10;
    /** On propose de finir une ligne quand il lui reste au plus ce nombre de stations. */
    private const LINE_REMAINING_MAX = 5;

    public function __construct(
        private readonly VisitHistoryService $history,
        private readonly Translator $translator,
    ) {
    }

    /**
     * @param list<array{id: int, number: string, total: int, visited: int}> $lines complétion par ligne
     *
     * @return list<array{key: string, title: string, current: int, target: int, lineId: ?int}>
     */
    public function forUser(User $user, array $lines): array
    {
        $visits = $this->history->firstVisits($user);
        $since = static fn (string $start): int => count(array_filter(
            $visits,
            static fn (\DateTimeImmutable $at) => $at >= new \DateTimeImmutable($start),
        ));

        $challenges = [
            [
                'key' => 'week',
                'title' => $this->translator->tf('%d nouvelles stations cette semaine', self::WEEKLY_TARGET),
                'current' => min(self::WEEKLY_TARGET, $since('monday this week')),
                'target' => self::WEEKLY_TARGET,
                'lineId' => null,
            ],
            [
                'key' => 'month',
                'title' => $this->translator->tf('%d nouvelles stations ce mois-ci', self::MONTHLY_TARGET),
                'current' => min(self::MONTHLY_TARGET, $since('first day of this month midnight')),
                'target' => self::MONTHLY_TARGET,
                'lineId' => null,
            ],
        ];

        // La ligne entamée la plus proche de la fin, s'il ne lui manque que quelques stations.
        $closest = null;
        foreach ($lines as $line) {
            $remaining = $line['total'] - $line['visited'];
            if ($line['visited'] > 0 && $remaining > 0 && $remaining <= self::LINE_REMAINING_MAX
                && ($closest === null || $remaining < $closest['total'] - $closest['visited'])) {
                $closest = $line;
            }
        }
        if ($closest !== null) {
            $challenges[] = [
                'key' => 'line',
                'title' => $this->translator->tf('Termine la ligne %s', $closest['number']),
                'current' => $closest['visited'],
                'target' => $closest['total'],
                'lineId' => $closest['id'],
            ];
        }

        return $challenges;
    }
}
