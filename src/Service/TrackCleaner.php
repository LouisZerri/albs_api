<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;

/**
 * Nettoie le tracé d'une ligne pour l'affichage sur la carte.
 *
 * Le tracé officiel (IDFM) décrit chaque ligne par plusieurs parcours — un par sens et par
 * variante de service — qui se superposent presque entièrement, et il inclut des voies de
 * service (boucles de retournement, raccordements). Affiché tel quel : la même ligne dessinée
 * jusqu'à dix fois, et par endroits un « double trait » qui ne correspond à aucun trajet de
 * voyageur (ex. l'ancienne boucle de Porte de Clichy sur la 13).
 *
 * 1. Dédoublonnage : on ne garde d'un parcours que ce qui s'écarte de ce qui est déjà gardé.
 * 2. Tri des écarts : un morceau à part n'est conservé que s'il dessert une station que le reste
 *    du tracé ne dessert pas (vraie branche : 7, 13 ; boucle parcourue : 10, 7bis).
 *
 * On ne touche PAS à la base (le web la possède) : calcul à l'affichage, mis en cache.
 */
final class TrackCleaner
{
    /** En deçà, deux voies sont le même trait sur la carte (voies parallèles d'un même tunnel). */
    private const SAME_TRACK_M = 12.0;
    /** Une station « appartient » à un morceau si elle en est plus proche que du reste d'au moins ça. */
    private const SERVES_MARGIN_M = 25.0;
    private const METERS_PER_DEGREE = 111320.0;
    /** À incrémenter si l'algorithme ou ses réglages changent (invalide le cache). */
    private const CACHE_VERSION = 1;

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * Nettoie toutes les lignes d'un coup. Le calcul prend quelques secondes : résultat mis en
     * cache, avec une clé dérivée des données → recalculé tout seul si un tracé ou une station change.
     *
     * @param array<int, mixed>                                   $geometryByLine  lineId → parcours (listes de [lng, lat])
     * @param array<int, list<array{lat: float, lng: float}>>     $platformsByLine lineId → quais de la ligne
     *
     * @return array<int, list<list<array{float, float}>>> lineId → tracé nettoyé (même format)
     */
    public function cleanAll(array $geometryByLine, array $platformsByLine): array
    {
        $key = 'track_clean_'.self::CACHE_VERSION.'_'.hash('xxh128', serialize([$geometryByLine, $platformsByLine]));

        return $this->cache->get($key, function () use ($geometryByLine, $platformsByLine): array {
            $clean = [];
            foreach ($geometryByLine as $lineId => $geometry) {
                $clean[$lineId] = $this->clean($geometry, $platformsByLine[$lineId] ?? []);
            }

            return $clean;
        });
    }

    /**
     * @param mixed                                 $geometry  parcours de la ligne (listes de [lng, lat])
     * @param list<array{lat: float, lng: float}>   $platforms quais de la ligne
     *
     * @return list<list<array{float, float}>>
     */
    public function clean(mixed $geometry, array $platforms): array
    {
        $routes = [];
        foreach (is_array($geometry) ? $geometry : [] as $route) {
            if (is_array($route) && count($route) > 1) {
                $routes[] = array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], array_values($route));
            }
        }
        if ($routes === []) {
            return [];
        }

        // Repère local en mètres, pour mesurer des distances simplement.
        $lat0 = $routes[0][0][1];
        $kx = cos(deg2rad($lat0)) * self::METERS_PER_DEGREE;
        $ky = self::METERS_PER_DEGREE;
        $local = static fn (array $route): array => array_map(
            static fn (array $p): array => [$p[0] * $kx, $p[1] * $ky],
            $route,
        );

        // Le plus long parcours sert de base ; les autres n'apportent que leurs écarts.
        usort($routes, fn (array $a, array $b): int => $this->length($local($b)) <=> $this->length($local($a)));

        /** @var list<list<array{float, float}>> $pieces morceaux gardés, en degrés */
        $pieces = [$routes[0]];
        /** @var list<list<array{float, float}>> $kept les mêmes, en mètres */
        $kept = [$local($routes[0])];

        foreach (array_slice($routes, 1) as $route) {
            foreach ($this->partsAwayFrom($route, $local($route), $kept) as $part) {
                $pieces[] = $part;
                $kept[] = $local($part);
            }
        }

        // Le premier morceau (la base) reste toujours ; les autres doivent desservir une station.
        $stations = array_map(static fn (array $p): array => [$p['lng'] * $kx, $p['lat'] * $ky], $platforms);
        $result = [$pieces[0]];
        for ($i = 1, $n = count($pieces); $i < $n; ++$i) {
            $others = $kept;
            unset($others[$i]);
            if ($this->servesAStation($kept[$i], $others, $stations)) {
                $result[] = $pieces[$i];
            }
        }

        return $result;
    }

    /**
     * Les portions d'un parcours qui s'écartent du tracé déjà gardé (avec leurs points de raccord).
     *
     * @param list<array{float, float}>       $route      en degrés
     * @param list<array{float, float}>       $routeLocal le même, en mètres
     * @param list<list<array{float, float}>> $kept       en mètres
     *
     * @return list<list<array{float, float}>> en degrés
     */
    private function partsAwayFrom(array $route, array $routeLocal, array $kept): array
    {
        $parts = [];
        $current = [];
        foreach ($route as $i => $point) {
            if ($this->distance($routeLocal[$i], $kept) > self::SAME_TRACK_M) {
                if ($current === [] && $i > 0) {
                    $current[] = $route[$i - 1]; // raccord avec le tracé gardé
                }
                $current[] = $point;
                continue;
            }
            if (count($current) > 1) {
                $current[] = $point; // raccord de fin
                $parts[] = $current;
            }
            $current = [];
        }
        if (count($current) > 1) {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * @param list<array{float, float}>            $piece
     * @param array<int, list<array{float, float}>> $others
     * @param list<array{float, float}>            $stations
     */
    private function servesAStation(array $piece, array $others, array $stations): bool
    {
        foreach ($stations as $station) {
            if ($this->distance($station, [$piece]) + self::SERVES_MARGIN_M < $this->distance($station, $others)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distance (m) d'un point au plus proche des tracés.
     *
     * @param array{float, float}                   $point
     * @param iterable<list<array{float, float}>>   $tracks
     */
    private function distance(array $point, iterable $tracks): float
    {
        [$x, $y] = $point;
        $bestSq = INF;
        foreach ($tracks as $track) {
            for ($i = 1, $n = count($track); $i < $n; ++$i) {
                [$ax, $ay] = $track[$i - 1];
                $dx = $track[$i][0] - $ax;
                $dy = $track[$i][1] - $ay;
                $lenSq = $dx * $dx + $dy * $dy;
                $t = $lenSq > 0.0 ? max(0.0, min(1.0, (($x - $ax) * $dx + ($y - $ay) * $dy) / $lenSq)) : 0.0;
                $sq = ($x - $ax - $t * $dx) ** 2 + ($y - $ay - $t * $dy) ** 2;
                if ($sq < $bestSq) {
                    $bestSq = $sq;
                }
            }
        }

        return sqrt($bestSq);
    }

    /** @param list<array{float, float}> $track en mètres */
    private function length(array $track): float
    {
        $length = 0.0;
        for ($i = 1, $n = count($track); $i < $n; ++$i) {
            $length += hypot($track[$i][0] - $track[$i - 1][0], $track[$i][1] - $track[$i - 1][1]);
        }

        return $length;
    }
}
