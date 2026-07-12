<?php

namespace App\Service;

/**
 * Dessertes DIRECTIONNELLES ciblées (sans GTFS) : quelques stations de 2 lignes seulement,
 * desservies dans un seul sens. Stations par NOM EXACT (comme le hub).
 *
 *  - ligne 10  : boucle d'Auteuil.
 *  - ligne 7bis : boucle du Pré-Saint-Gervais (aller par Place des Fêtes, retour par Danube).
 *
 * (Suffit pour le « bloc boucle · 2 sens » de l'app. Le GTFS/RoutePattern resterait pour un
 *  vrai besoin futur : horaires, multi-réseaux…)
 */
final class LineTopology
{
    private const CONFIG = [
        '10' => [
            'label' => "Boucle d'Auteuil",
            'directions' => [
                "Église d'Auteuil" => 'vers Boulogne',
                'Michel-Ange - Auteuil' => 'vers Boulogne',
                "Porte d'Auteuil" => 'vers Boulogne',
                'Michel-Ange - Molitor' => "vers Gare d'Austerlitz",
                'Chardon-Lagache' => "vers Gare d'Austerlitz",
                'Mirabeau' => "vers Gare d'Austerlitz",
            ],
        ],
        '7bis' => [
            'label' => 'Boucle du Pré-Saint-Gervais',
            'directions' => [
                'Place des Fêtes' => 'vers Pré-Saint-Gervais',
                'Danube' => 'vers Louis Blanc',
            ],
        ],
    ];

    /**
     * @return array{label:string, directions:array<string,string>}|null
     */
    public function forLineNumber(?string $number): ?array
    {
        return $number !== null ? (self::CONFIG[$number] ?? null) : null;
    }
}
