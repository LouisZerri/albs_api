<?php

namespace App\Controller;

use App\I18n\Translator;
use App\Content\StationFacts;
use App\Entity\Line;
use App\Repository\BonplanRepository;
use App\Repository\LineRepository;
use App\Service\StationHubService;
use App\Service\StationSnapper;
use App\Service\TrackCleaner;
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
        private readonly TrackCleaner $tracks,
        private readonly Translator $translator,
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
     * Détail d'une ligne : stations ordonnées + correspondances par station. Volontairement léger
     * (pas de tracé : la carte le prend dans /api/network) car l'app précharge les 16 lignes pour le hors-ligne.
     */
    #[Route('/api/lines/{id}', name: 'api_line_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(Line $line): JsonResponse
    {
        return $this->json($this->hub->lineDetail($line));
    }

    /**
     * Index de tous les quais : { id, name, lineId }. Léger et quasi immuable, il permet à l'app
     * d'appliquer hors ligne la règle du hub (« descendu » = tous les quais de même nom) et de
     * recalculer les compteurs sans le serveur. Données publiques.
     */
    #[Route('/api/network/platforms', name: 'api_network_platforms', methods: ['GET'])]
    public function platforms(): JsonResponse
    {
        $platforms = [];
        foreach ($this->lines->findAllWithStations() as $line) {
            foreach ($line->getStations() as $s) {
                $platforms[] = ['id' => $s->getId(), 'name' => $s->getName(), 'lineId' => $line->getId()];
            }
        }

        return $this->json($platforms);
    }

    /**
     * Tout le réseau pour la carte : tracé de chaque ligne (nettoyé de ses doublons et voies de
     * service par TrackCleaner) + stations (dédupliquées par nom).
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
        $platformOnLine = []; // nom → [lineId => coordonnées du quai de cette ligne]
        $geometryByLine = [];
        $allLines = $this->lines->findAllWithStations();

        // Tracés nettoyés : c'est sur eux que les lignes sont dessinées ET que les points sont recalés.
        $rawGeometry = [];
        $platformsByLine = [];
        foreach ($allLines as $line) {
            $rawGeometry[$line->getId()] = $line->getGeometry();
            foreach ($line->getStations() as $s) {
                if ($s->getLatitude() !== null && $s->getLongitude() !== null) {
                    $platformsByLine[$line->getId()][] = ['lat' => $s->getLatitude(), 'lng' => $s->getLongitude()];
                }
            }
        }
        $geometryByLine = $this->tracks->cleanAll($rawGeometry, $platformsByLine);

        foreach ($allLines as $line) {
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
                'geometry' => $geometryByLine[$line->getId()] ?? [],
            ];

            foreach ($line->getStations() as $s) {
                $name = $s->getName();
                // Toute ligne desservant ce nom est une correspondance (dédup par lineId).
                $linesByName[$name][$line->getId()] = $lineBadge;

                if ($s->getLatitude() === null || $s->getLongitude() === null) {
                    continue;
                }
                $platforms[$name][] = ['lat' => $s->getLatitude(), 'lng' => $s->getLongitude()];
                $lineIds[$name][$line->getId()] = $line->getId();
                $platformOnLine[$name][$line->getId()] ??= ['lat' => $s->getLatitude(), 'lng' => $s->getLongitude()];

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
            $stations[$i]['lines'] = $this->linesWithOwnPosition(
                $linesByName[$station['name']] ?? [],
                $platformOnLine[$station['name']] ?? [],
                $geometryByLine,
            );
            $stations[$i]['bonplanCount'] = $bonplanCounts[$station['name']] ?? 0;
            $stations[$i]['fact'] = $this->translator->t(StationFacts::for($station['name']));
        }

        return $this->json(['lines' => $lines, 'stations' => $stations]);
    }

    /**
     * Les lignes d'une station, chacune avec la position de la station SUR CETTE LIGNE (`lat`/`lng`).
     *
     * Un pôle n'a qu'un point sur la carte, placé au mieux entre ses lignes : quand elles sont
     * éloignées (Montparnasse), il ne peut pas être sur toutes. Quand l'app met UNE ligne en avant,
     * elle utilise cette position-là, pile sur le trait. Inutile pour une station d'une seule ligne
     * (son point y est déjà) : `lat`/`lng` ne sont alors pas envoyés.
     *
     * @param array<int, array<string, mixed>>               $lines       lineId → badge de ligne
     * @param array<int, array{lat: float, lng: float}>      $platforms   lineId → quai de cette ligne
     * @param array<int, mixed>                              $geometryByLine
     *
     * @return list<array<string, mixed>>
     */
    private function linesWithOwnPosition(array $lines, array $platforms, array $geometryByLine): array
    {
        if (count($lines) < 2) {
            return array_values($lines);
        }

        foreach ($lines as $lineId => $badge) {
            if (isset($platforms[$lineId])) {
                $lines[$lineId] = $badge + $this->snapper->snap([$platforms[$lineId]], [$geometryByLine[$lineId] ?? []]);
            }
        }

        return array_values($lines);
    }
}
