<?php

namespace App\Service;

use App\Entity\Line;
use App\Repository\BonplanRepository;
use App\Repository\LineRepository;

/**
 * Correspondances : regroupement des stations par NOM EXACT (une station physique = même nom).
 * « Châtelet » ≠ « Les Halles » ≠ « Châtelet–Les Halles » : jamais fusionnés.
 *
 * Ajoute aussi, pour les lignes concernées (10, 7bis), la desserte directionnelle
 * (`directionLabel` par station + `loop` au niveau ligne) via LineTopology.
 */
final class StationHubService
{
    public function __construct(
        private readonly LineRepository $lines,
        private readonly LineTopology $topology,
        private readonly BonplanRepository $bonplans,
    ) {
    }

    /**
     * Détail d'une ligne : infos + stations ordonnées, chacune avec ses correspondances et,
     * le cas échéant, son sens de desserte (`directionLabel`).
     *
     * @return array<string, mixed>
     */
    public function lineDetail(Line $line): array
    {
        $lineMap = $this->buildLineMap();
        $topo = $this->topology->forLineNumber($line->getNumber());
        $bonplanCounts = $this->bonplans->countByStationName();

        $stations = [];
        foreach ($line->getStations() as $station) {
            $correspondences = [];
            foreach ($lineMap[$station->getName()] ?? [] as $other) {
                if ($other['lineId'] !== $line->getId()) {
                    $correspondences[] = $other;
                }
            }

            $stations[] = [
                'id' => $station->getId(),
                'name' => $station->getName(),
                'position' => $station->getPosition(),
                'branch' => $station->getBranch(),
                'latitude' => $station->getLatitude(),
                'longitude' => $station->getLongitude(),
                'correspondences' => $correspondences,
                // Bons plans autour de la station physique (lien vers l'onglet Bons plans).
                'bonplanCount' => $bonplanCounts[$station->getName()] ?? 0,
                // Desserte à sens unique (boucle 10 / 7bis) ; null = desservie dans les deux sens.
                'directionLabel' => $topo['directions'][$station->getName()] ?? null,
            ];
        }

        return [
            'id' => $line->getId(),
            'number' => $line->getNumber(),
            'name' => $line->getName(),
            'color' => $line->getColor(),
            'textColor' => $line->getTextColor(),
            'geometry' => $line->getGeometry(),
            // En-tête du « bloc boucle » quand la ligne a une desserte directionnelle, sinon null.
            'loop' => $topo !== null ? ['label' => $topo['label']] : null,
            'stations' => $stations,
        ];
    }

    /**
     * nom de station => liste des lignes qui la desservent (dédupliquées par ligne).
     *
     * @return array<string, list<array{lineId:int, number:string, color:string, textColor:?string}>>
     */
    private function buildLineMap(): array
    {
        $map = [];
        $seen = [];

        foreach ($this->lines->findAllWithStations() as $line) {
            $info = [
                'lineId' => $line->getId(),
                'number' => $line->getNumber(),
                'color' => $line->getColor(),
                'textColor' => $line->getTextColor(),
            ];

            foreach ($line->getStations() as $station) {
                $name = $station->getName();
                $key = $name . '#' . $line->getId();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $map[$name][] = $info;
            }
        }

        return $map;
    }
}
