<?php

namespace App\Controller;

use App\Entity\Line;
use App\Repository\LineRepository;
use App\Service\StationHubService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class LineController extends AbstractController
{
    public function __construct(
        private readonly StationHubService $hub,
        private readonly LineRepository $lines,
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
     * Données publiques.
     */
    #[Route('/api/network', name: 'api_network', methods: ['GET'])]
    public function network(): JsonResponse
    {
        $lines = [];
        $stations = [];
        $index = [];        // nom → index dans $stations (déduplication)
        $linesByName = [];  // nom → [lineId => badge de ligne] (correspondances)

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

            foreach ($line->getStations() as $s) {
                $name = $s->getName();
                // Toute ligne desservant ce nom est une correspondance (dédup par lineId).
                $linesByName[$name][$line->getId()] = $lineBadge;

                if (isset($index[$name]) || $s->getLatitude() === null || $s->getLongitude() === null) {
                    continue;
                }
                $index[$name] = count($stations);
                $stations[] = [
                    'id' => $s->getId(),
                    'name' => $name,
                    'lat' => $s->getLatitude(),
                    'lng' => $s->getLongitude(),
                ];
            }
        }

        // Attache à chaque station la liste (ordonnée, sans doublon) de ses lignes.
        foreach ($stations as $i => $station) {
            $stations[$i]['lines'] = array_values($linesByName[$station['name']] ?? []);
        }

        return $this->json(['lines' => $lines, 'stations' => $stations]);
    }
}
