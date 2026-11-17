<?php

namespace App\Content;

/**
 * Stations fantômes : des stations fermées ou jamais ouvertes, qu'on traverse sans les voir.
 *
 * On ne peut pas y descendre : chacune se « débloque » quand l'utilisateur est descendu aux
 * stations qui l'encadrent (`unlockedBy`, noms EXACTS) — il est alors forcément passé devant.
 * Aucune donnée n'est enregistrée : c'est déduit de la progression existante.
 */
final class GhostStations
{
    /**
     * @return list<array{key: string, name: string, lines: string, story: string, unlockedBy: list<string>}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'arsenal',
                'name' => 'Arsenal',
                'lines' => 'Ligne 5',
                'story' => 'Fermée en septembre 1939, à la mobilisation, elle n’a jamais rouvert. Les rames de la ligne 5 la traversent toujours sans s’arrêter.',
                'unlockedBy' => ['Bastille', 'Quai de la Rapée'],
            ],
            [
                'key' => 'champ-de-mars',
                'name' => 'Champ de Mars',
                'lines' => 'Ligne 8',
                'story' => 'Elle aussi fermée en 1939 et jamais rouverte : ses quais dorment sous le quartier de l’École militaire.',
                'unlockedBy' => ['La Motte-Picquet - Grenelle', 'École Militaire'],
            ],
            [
                'key' => 'croix-rouge',
                'name' => 'Croix-Rouge',
                'lines' => 'Ligne 10',
                'story' => 'Ancien terminus de la ligne 10, fermée en 1939. On devine encore ses quais depuis la rame.',
                'unlockedBy' => ['Sèvres - Babylone', 'Mabillon'],
            ],
            [
                'key' => 'saint-martin',
                'name' => 'Saint-Martin',
                'lines' => 'Lignes 8 et 9',
                'story' => 'Fermée en 1939, rouverte brièvement, puis abandonnée : elle était trop proche de Strasbourg - Saint-Denis.',
                'unlockedBy' => ['Strasbourg - Saint-Denis', 'République'],
            ],
            [
                'key' => 'haxo',
                'name' => 'Haxo',
                'lines' => 'Entre la 3bis et la 7bis',
                'story' => 'La plus secrète : construite sur une voie reliant les lignes 3bis et 7bis, elle n’a jamais accueilli un seul voyageur et n’a aucun accès vers la rue.',
                'unlockedBy' => ['Porte des Lilas', 'Pré-Saint-Gervais'],
            ],
        ];
    }
}
