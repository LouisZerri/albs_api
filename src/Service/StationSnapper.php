<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;

/**
 * Recale le point d'une station (carte) sur le tracé de ses lignes.
 *
 * Les coordonnées en base sont parfois celles du centre d'un pôle, pas du quai : le point
 * s'affiche alors à côté du trait. On ne touche PAS à la base (le web la possède) : le
 * recalage est calculé à la volée, pour l'affichage seulement.
 *
 * - Station d'une seule ligne  → projection sur le tracé de cette ligne.
 * - Pôle (plusieurs lignes)    → le point d'un des tracés le plus proche de TOUS les autres
 *                                (≈ leur croisement), sans trop s'éloigner de la position d'origine.
 */
final class StationSnapper
{
    /** Rayon de recherche du tracé autour de la station. Au-delà, on garde la coordonnée d'origine. */
    private const RADIUS_M = 500.0;
    /** Pas d'échantillonnage des tracés pour chercher le meilleur point d'un pôle. */
    private const STEP_M = 15.0;
    /** Poids de l'éloignement à la position d'origine (départage les lignes parallèles). */
    private const ANCHOR_WEIGHT = 0.5;
    private const METERS_PER_DEGREE = 111320.0;
    /** À incrémenter si l'algorithme ou ses réglages changent (invalide le cache). */
    private const CACHE_VERSION = 3;

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * Recale toutes les stations d'un coup. Le calcul est coûteux (plusieurs secondes) : le
     * résultat est mis en cache, avec une clé dérivée des données d'entrée → il se recalcule
     * tout seul dès qu'une coordonnée ou un tracé change en base (aucune péremption à gérer).
     *
     * @param array<string, list<array{lat: float, lng: float}>> $platformsByName nom → quais
     * @param array<string, list<int>>                           $lineIdsByName   nom → lignes desservant la station
     * @param array<int, mixed>                                  $geometryByLine  lineId → géométrie
     *
     * @return array<string, array{lat: float, lng: float}> nom → position recalée
     */
    public function snapAll(array $platformsByName, array $lineIdsByName, array $geometryByLine): array
    {
        $key = 'station_snap_'.self::CACHE_VERSION.'_'
            .hash('xxh128', serialize([$platformsByName, $lineIdsByName, $geometryByLine]));

        return $this->cache->get($key, function () use ($platformsByName, $lineIdsByName, $geometryByLine): array {
            $positions = [];
            foreach ($platformsByName as $name => $platforms) {
                $geometries = array_map(
                    static fn (int $lineId) => $geometryByLine[$lineId] ?? [],
                    $lineIdsByName[$name] ?? [],
                );
                $positions[$name] = $this->snap($platforms, $geometries);
            }

            return $positions;
        });
    }

    /**
     * @param list<array{lat: float, lng: float}> $platforms  Coordonnées des quais de la station (≥ 1)
     * @param list<mixed>                         $geometries Géométrie de chaque ligne desservant la station
     *                                                        (segments de [lng, lat])
     *
     * @return array{lat: float, lng: float}
     */
    public function snap(array $platforms, array $geometries): array
    {
        // Ancre = barycentre des quais ; repère local en mètres centré dessus.
        $lat0 = array_sum(array_column($platforms, 'lat')) / count($platforms);
        $lng0 = array_sum(array_column($platforms, 'lng')) / count($platforms);
        $kx = cos(deg2rad($lat0)) * self::METERS_PER_DEGREE;
        $ky = self::METERS_PER_DEGREE;

        $tracks = [];
        foreach ($geometries as $geometry) {
            $pieces = $this->localPieces($geometry, $lat0, $lng0, $kx, $ky);
            if ($pieces !== []) {
                $tracks[] = $pieces;
            }
        }

        if ($tracks === []) {
            return ['lat' => $platforms[0]['lat'], 'lng' => $platforms[0]['lng']];
        }

        [$x, $y] = count($tracks) === 1
            ? $this->project(0.0, 0.0, $tracks[0])
            : $this->bestHubPoint($tracks);

        return [
            'lat' => round($lat0 + $y / $ky, 6),
            'lng' => round($lng0 + $x / $kx, 6),
        ];
    }

    /**
     * Tronçons du tracé proches de l'ancre, en mètres locaux.
     *
     * @return list<array{float, float, float, float}> [ax, ay, bx, by]
     */
    private function localPieces(mixed $geometry, float $lat0, float $lng0, float $kx, float $ky): array
    {
        $pieces = [];
        foreach (is_array($geometry) ? $geometry : [] as $segment) {
            $prev = null;
            foreach (is_array($segment) ? $segment : [] as $point) {
                $cur = [((float) $point[0] - $lng0) * $kx, ((float) $point[1] - $lat0) * $ky];
                // Garde le tronçon si sa boîte englobante touche la zone de recherche
                // (un long tronçon peut passer près de la station sans qu'un sommet y soit).
                if (
                    $prev !== null
                    && min($prev[0], $cur[0]) <= self::RADIUS_M && max($prev[0], $cur[0]) >= -self::RADIUS_M
                    && min($prev[1], $cur[1]) <= self::RADIUS_M && max($prev[1], $cur[1]) >= -self::RADIUS_M
                ) {
                    $pieces[] = [$prev[0], $prev[1], $cur[0], $cur[1]];
                }
                $prev = $cur;
            }
        }

        return $pieces;
    }

    /**
     * Point d'un des tracés minimisant la somme des distances à tous les tracés du pôle.
     *
     * @param non-empty-list<list<array{float, float, float, float}>> $tracks
     *
     * @return array{float, float}
     */
    private function bestHubPoint(array $tracks): array
    {
        $best = [0.0, 0.0];
        $bestCost = INF;

        foreach ($tracks as $track) {
            foreach ($track as [$ax, $ay, $bx, $by]) {
                $steps = max(1, (int) ceil(hypot($bx - $ax, $by - $ay) / self::STEP_M));
                for ($i = 0; $i <= $steps; ++$i) {
                    $x = $ax + ($bx - $ax) * $i / $steps;
                    $y = $ay + ($by - $ay) * $i / $steps;

                    $cost = self::ANCHOR_WEIGHT * hypot($x, $y);
                    foreach ($tracks as $other) {
                        [$px, $py] = $this->project($x, $y, $other);
                        $cost += hypot($x - $px, $y - $py);
                        if ($cost >= $bestCost) {
                            continue 2;
                        }
                    }
                    $best = [$x, $y];
                    $bestCost = $cost;
                }
            }
        }

        return $best;
    }

    /**
     * Projection orthogonale du point (x, y) sur le tracé (point le plus proche).
     *
     * @param non-empty-list<array{float, float, float, float}> $track
     *
     * @return array{float, float}
     */
    private function project(float $x, float $y, array $track): array
    {
        $best = [0.0, 0.0];
        $bestSq = INF;

        foreach ($track as [$ax, $ay, $bx, $by]) {
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $lenSq = $dx * $dx + $dy * $dy;
            $t = $lenSq > 0.0 ? max(0.0, min(1.0, (($x - $ax) * $dx + ($y - $ay) * $dy) / $lenSq)) : 0.0;
            $px = $ax + $t * $dx;
            $py = $ay + $t * $dy;
            $sq = ($x - $px) ** 2 + ($y - $py) ** 2;
            if ($sq < $bestSq) {
                $bestSq = $sq;
                $best = [$px, $py];
            }
        }

        return $best;
    }
}
