<?php

namespace App\Service;

use App\I18n\Translator;
use App\Content\GhostStations;
use App\Content\JourneyCatalog;
use App\Entity\User;
use App\Repository\StationRepository;

/**
 * Onglet « Découvrir » : parcours thématiques et stations fantômes, avec l'avancement de
 * l'utilisateur. Le contenu vient de App\Content ; l'avancement est déduit des stations visitées.
 */
final class DiscoveryService
{
    public function __construct(
        private readonly VisitHistoryService $history,
        private readonly StationRepository $stations,
        private readonly Translator $translator,
    ) {
    }

    /**
     * Parcours, chacun avec ses étapes (station, lignes, visitée ou non) et son avancement.
     *
     * @return list<array<string, mixed>>
     */
    public function journeys(User $user): array
    {
        $visited = $this->history->firstVisits($user);
        $ids = $this->stations->representativeIdsByName();
        $lines = $this->stations->linesByStationName();

        return array_map(function (array $journey) use ($visited, $ids, $lines): array {
            $stops = [];
            foreach ($journey['stops'] as $name => $note) {
                // Station inconnue (renommée en base) : on l'ignore plutôt que de casser le parcours.
                if (!isset($ids[$name])) {
                    continue;
                }
                $stops[] = [
                    'stationId' => $ids[$name],
                    'name' => $name,
                    'note' => $this->translator->t($note),
                    'lines' => $lines[$name] ?? [],
                    'visited' => isset($visited[$name]),
                ];
            }

            return [
                'key' => $journey['key'],
                'title' => $this->translator->t($journey['title']),
                'icon' => $journey['icon'],
                'pitch' => $this->translator->t($journey['pitch']),
                'visited' => count(array_filter($stops, static fn (array $s) => $s['visited'])),
                'total' => count($stops),
                'stops' => $stops,
            ];
        }, JourneyCatalog::all());
    }

    /**
     * Stations fantômes. L'histoire d'une station n'est renvoyée qu'une fois débloquée (l'utilisateur
     * est descendu aux stations qui l'encadrent) : avant, on ne donne que l'indice pour y arriver.
     *
     * @return list<array<string, mixed>>
     */
    public function ghostStations(User $user): array
    {
        $visited = $this->history->firstVisits($user);

        return array_map(function (array $ghost) use ($visited): array {
            $missing = array_values(array_filter($ghost['unlockedBy'], static fn (string $n) => !isset($visited[$n])));
            $unlocked = $missing === [];

            return [
                'key' => $ghost['key'],
                'name' => $ghost['name'],
                'lines' => $this->translator->t($ghost['lines']),
                'unlocked' => $unlocked,
                'story' => $unlocked ? $this->translator->t($ghost['story']) : null,
                'unlockedBy' => $ghost['unlockedBy'],
                'missing' => $missing,
            ];
        }, GhostStations::all());
    }
}
