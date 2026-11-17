<?php

namespace App\Content;

/**
 * Parcours thématiques : des listes de stations à visiter, pour donner un programme
 * (touristes) ou un objectif (habitués). Contenu éditorial, rangé ici tant qu'il change peu.
 *
 * `stops` : nom EXACT de la station => courte note (ou null). L'ordre est celui de la visite conseillée.
 */
final class JourneyCatalog
{
    /**
     * @return list<array{key: string, title: string, icon: string, pitch: string, stops: array<string, ?string>}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'incontournables',
                'title' => 'Les incontournables',
                'icon' => '🗼',
                'pitch' => 'Les grands monuments de Paris en dix stations.',
                'stops' => [
                    'Charles de Gaulle - Étoile' => 'Arc de triomphe',
                    'Trocadéro' => 'Vue sur la tour Eiffel',
                    'Bir-Hakeim' => 'Tour Eiffel',
                    'Concorde' => 'Place de la Concorde',
                    'Palais Royal - Musée du Louvre' => 'Musée du Louvre',
                    'Cité' => 'Notre-Dame',
                    'Hôtel de Ville' => 'Hôtel de Ville et le Marais',
                    'Bastille' => 'Place de la Bastille',
                    'Opéra' => 'Palais Garnier',
                    'Anvers' => 'Sacré-Cœur',
                ],
            ],
            [
                'key' => 'belles-stations',
                'title' => 'Les plus belles stations',
                'icon' => '🎨',
                'pitch' => 'Dix quais qui valent le détour, sans même sortir du métro.',
                'stops' => [
                    'Arts et Métiers' => 'Le sous-marin de cuivre',
                    'Concorde' => 'La Déclaration des droits de l’homme',
                    'Louvre - Rivoli' => 'L’antichambre du musée',
                    'Abbesses' => 'La plus profonde',
                    'Cluny - La Sorbonne' => 'La mosaïque des signatures',
                    'Liège' => 'Les céramiques',
                    'Pont Neuf' => 'Les pièces de monnaie',
                    'Varenne' => 'Les sculptures de Rodin',
                    'Tuileries' => 'Un siècle en fresques',
                    'Bastille' => 'La fresque de la Révolution',
                ],
            ],
            [
                'key' => 'montmartre',
                'title' => 'Montmartre',
                'icon' => '⛪',
                'pitch' => 'Le tour de la butte, du Sacré-Cœur au Moulin Rouge.',
                'stops' => [
                    'Anvers' => 'Sacré-Cœur',
                    'Abbesses' => 'Place des Abbesses',
                    'Pigalle' => null,
                    'Blanche' => 'Moulin Rouge',
                    'Lamarck - Caulaincourt' => 'Le versant nord de la butte',
                ],
            ],
            [
                'key' => 'aerien',
                'title' => 'Le métro aérien',
                'icon' => '🌉',
                'pitch' => 'Les stations en viaduc des lignes 2 et 6, pour voir Paris depuis la rame.',
                'stops' => [
                    'Passy' => null,
                    'Bir-Hakeim' => null,
                    'Dupleix' => null,
                    'La Motte-Picquet - Grenelle' => null,
                    'Cambronne' => null,
                    'Sèvres - Lecourbe' => null,
                    'Chevaleret' => null,
                    'Quai de la Gare' => null,
                    'Barbès - Rochechouart' => null,
                    'La Chapelle' => null,
                    'Stalingrad' => null,
                    'Jaurès' => null,
                ],
            ],
        ];
    }
}
