<?php

namespace App\Controller;

use App\Entity\Line;
use App\Repository\BonplanRepository;
use App\Repository\LineRepository;
use App\Service\StationHubService;
use App\Service\StationSnapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class LineController extends AbstractController
{
    public function __construct(
        private readonly StationHubService $hub,
        private readonly LineRepository $lines,
        private readonly StationSnapper $snapper,
        private readonly BonplanRepository $bonplans,
    ) {
    }

    /**
     * Liste légère des 16 lignes (onglet Réseau). Données publiques.
     */
    #[Route('/api/lines', name: 'api_lines_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $data = array_map(static fn (Line $l) => [
            'id' => $l->getId(),
            'number' => $l->getNumber(),
            'name' => $l->getName(),
            'color' => $l->getColor(),
            'textColor' => $l->getTextColor(),
        ], $this->lines->findBy([], ['id' => 'ASC']));

        return $this->json($data);
    }

    /**
     * Détail d'une ligne : stations ordonnées + correspondances par station (+ géométrie).
     */
    #[Route('/api/lines/{id}', name: 'api_line_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(Line $line): JsonResponse
    {
        return $this->json($this->hub->lineDetail($line));
    }

    /**
     * Tout le réseau pour la carte : géométrie de chaque ligne + stations (dédupliquées par nom).
     * Le point de chaque station est recalé sur le tracé de ses lignes (StationSnapper).
     * `bonplanCount` = bons plans autour de la station (lien vers l'onglet Bons plans).
     * Données publiques.
     */
    #[Route('/api/network', name: 'api_network', methods: ['GET'])]
    public function network(): JsonResponse
    {
        $lines = [];
        $stations = [];
        $index = [];        // nom → index dans $stations (déduplication)
        $linesByName = [];  // nom → [lineId => badge de ligne] (correspondances)
        $platforms = [];    // nom → coordonnées de chaque quai
        $lineIds = [];      // nom → ids des lignes desservant la station
        $geometryByLine = [];

        foreach ($this->lines->findAllWithStations() as $line) {
            $lineBadge = [
                'lineId' => $line->getId(),
                'number' => $line->getNumber(),
                'color' => $line->getColor(),
                'textColor' => $line->getTextColor(),
            ];
            $lines[] = [
                'id' => $line->getId(),
                'number' => $line->getNumber(),
                'color' => $line->getColor(),
                'textColor' => $line->getTextColor(),
                'geometry' => $line->getGeometry(),
            ];
            $geometryByLine[$line->getId()] = $line->getGeometry();

            foreach ($line->getStations() as $s) {
                $name = $s->getName();
                // Toute ligne desservant ce nom est une correspondance (dédup par lineId).
                $linesByName[$name][$line->getId()] = $lineBadge;

                if ($s->getLatitude() === null || $s->getLongitude() === null) {
                    continue;
                }
                $platforms[$name][] = ['lat' => $s->getLatitude(), 'lng' => $s->getLongitude()];
                $lineIds[$name][$line->getId()] = $line->getId();

                if (!isset($index[$name])) {
                    $index[$name] = count($stations);
                    $stations[] = ['id' => $s->getId(), 'name' => $name];
                }
            }
        }

        // Position recalée sur le tracé + liste (ordonnée, sans doublon) des lignes de la station.
        $bonplanCounts = $this->bonplans->countByStationName();
        $positions = $this->snapper->snapAll($platforms, array_map(array_values(...), $lineIds), $geometryByLine);
        foreach ($stations as $i => $station) {
            $stations[$i] += $positions[$station['name']];
            $stations[$i]['lines'] = array_values($linesByName[$station['name']] ?? []);
            $stations[$i]['bonplanCount'] = $bonplanCounts[$station['name']] ?? 0;
        }

        return $this->json(['lines' => $lines, 'stations' => $stations]);
    }
}
