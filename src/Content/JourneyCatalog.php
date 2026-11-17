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
                'pitch' => 'Les grands monuments de Paris, station par station.',
                'stops' => [
                    'Charles de Gaulle - Étoile' => 'Arc de triomphe',
                    'Trocadéro' => 'Vue sur la tour Eiffel',
                    'Bir-Hakeim' => 'Tour Eiffel',
                    'Invalides' => 'Hôtel des Invalides',
                    'Assemblée Nationale' => 'Palais Bourbon',
                    'Solférino' => 'Musée d’Orsay',
                    'Champs-Élysées - Clemenceau' => 'Grand Palais et Petit Palais',
                    'Concorde' => 'Place de la Concorde',
                    'Madeleine' => 'Église de la Madeleine',
                    'Opéra' => 'Palais Garnier',
                    'Palais Royal - Musée du Louvre' => 'Musée du Louvre',
                    'Rambuteau' => 'Centre Pompidou',
                    'Cité' => 'Notre-Dame',
                    'Hôtel de Ville' => 'Hôtel de Ville et le Marais',
                    'Saint-Paul' => 'Place des Vosges',
                    'Bastille' => 'Place de la Bastille',
                    'Saint-Germain-des-Prés' => 'Église et cafés de Saint-Germain',
                    // Ni le Sénat ni le Panthéon n'ont de station de métro à leur nom : on prend la plus proche.
                    'Odéon' => 'Sénat et jardin du Luxembourg',
                    'Cardinal Lemoine' => 'Panthéon',
                    'Montparnasse - Bienvenüe' => 'Tour Montparnasse',
                    'Anvers' => 'Sacré-Cœur',
                ],
            ],
            [
                'key' => 'amoureux',
                'title' => 'Paris des amoureux',
                'icon' => '💕',
                'pitch' => 'La ville de l’amour en dix étapes, à faire à deux.',
                'stops' => [
                    'Abbesses' => 'Le Mur des je t’aime',
                    'Anvers' => 'Coucher de soleil au Sacré-Cœur',
                    'Saint-Georges' => 'Musée de la Vie romantique',
                    'Palais Royal - Musée du Louvre' => 'Jardins du Palais-Royal',
                    'Louvre - Rivoli' => 'Pont des Arts',
                    'Pont Neuf' => 'Square du Vert-Galant',
                    'Pont Marie' => 'Île Saint-Louis',
                    'Odéon' => 'Fontaine Médicis, jardin du Luxembourg',
                    'Alma - Marceau' => 'Croisière sur la Seine',
                    'Trocadéro' => 'La tour Eiffel illuminée',
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
            [
                'key' => 'insolite',
                'title' => 'Le Paris insolite',
                'icon' => '🕯️',
                'pitch' => 'Pour ceux qui ont déjà fait les classiques.',
                'stops' => [
                    'Denfert-Rochereau' => 'Catacombes',
                    'Père Lachaise' => 'Cimetière du Père-Lachaise',
                    'Jacques Bonsergent' => 'Canal Saint-Martin',
                    'Place Monge' => 'Arènes de Lutèce et Grande Mosquée',
                ],
            ],
            [
                'key' => 'portes-de-paris',
                'title' => 'Aux portes de Paris',
                'icon' => '🏰',
                'pitch' => 'Trois monuments au bout des lignes.',
                'stops' => [
                    'La Défense' => 'Grande Arche',
                    'Château de Vincennes' => 'Château de Vincennes',
                    'Basilique de Saint-Denis' => 'Basilique des rois de France',
                ],
            ],
        ];
    }
}
