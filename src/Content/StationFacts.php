<?php

namespace App\Content;

/**
 * « Pourquoi descendre ici ? » : une phrase par station remarquable.
 *
 * Contenu éditorial, rangé ici (et non en base) tant qu'il change peu — même principe que
 * LineTopology. Clé = nom EXACT de la station. À enrichir librement : une station absente de
 * la liste n'affiche simplement rien.
 */
final class StationFacts
{
    private const FACTS = [
        'Abbesses' => 'La station la plus profonde du réseau, à 36 mètres sous la butte Montmartre, avec l’un des rares édicules Guimard à verrière encore en place.',
        'Anvers' => 'Le point de départ pour monter au Sacré-Cœur, à pied ou par le funiculaire.',
        'Arts et Métiers' => 'Sur les quais de la ligne 11, un décor de sous-marin tout en cuivre, imaginé par le dessinateur François Schuiten en hommage à Jules Verne.',
        'Bastille' => 'Sur le quai de la ligne 1, une fresque raconte la Révolution ; sur celui de la ligne 5, on voit encore des vestiges de la forteresse.',
        'Bir-Hakeim' => 'Station aérienne au pied de la tour Eiffel ; le viaduc qui suit traverse la Seine avec l’une des plus belles vues de Paris.',
        'Charles de Gaulle - Étoile' => 'Sous l’Arc de triomphe, au point de départ de douze avenues.',
        'Chaussée d\'Antin - La Fayette' => 'La voûte de la ligne 9 porte une grande fresque consacrée à La Fayette et à l’amitié franco-américaine.',
        'Cité' => 'La seule station de l’île de la Cité, à deux pas de Notre-Dame et de la Sainte-Chapelle.',
        'Cluny - La Sorbonne' => 'Au plafond, une mosaïque reproduit les signatures de grandes figures du Quartier latin.',
        'Concorde' => 'Sur les quais de la ligne 12, les carreaux de la voûte reprennent lettre par lettre la Déclaration des droits de l’homme et du citoyen.',
        'Liège' => 'Les quais sont décorés de céramiques représentant des paysages de la province de Liège.',
        'Louvre - Rivoli' => 'Des copies d’œuvres du musée du Louvre sont exposées sur les quais, comme une antichambre du musée.',
        'Montparnasse - Bienvenüe' => 'Son nom rend hommage à Fulgence Bienvenüe, l’ingénieur considéré comme le père du métro parisien.',
        'Opéra' => 'Au pied du palais Garnier.',
        'Palais Royal - Musée du Louvre' => 'L’une de ses entrées est une œuvre d’art : le Kiosque des noctambules, en perles de verre colorées, de Jean-Michel Othoniel.',
        'Passy' => 'Le métro y sort de la colline pour s’élancer sur le viaduc de Bir-Hakeim, face à la tour Eiffel.',
        'Pont Neuf' => 'Des reproductions géantes de pièces de monnaie ornent la voûte : la Monnaie de Paris est juste au-dessus.',
        'Porte des Lilas' => 'Un de ses quais, fermé au public, sert de décor de cinéma : de nombreux films s’y sont tournés.',
        'Trocadéro' => 'La sortie mène à l’esplanade qui offre la vue la plus célèbre sur la tour Eiffel.',
        'Tuileries' => 'Les fresques des quais retracent un siècle d’histoire, décennie par décennie, depuis l’ouverture du métro en 1900.',
        'Varenne' => 'Des reproductions de sculptures de Rodin, dont Le Penseur, accompagnent les voyageurs : le musée Rodin est à la sortie.',
    ];

    public static function for(string $stationName): ?string
    {
        return self::FACTS[$stationName] ?? null;
    }

    /** @return list<string> noms des stations ayant un texte (pour vérifier qu'ils existent en base) */
    public static function stationNames(): array
    {
        return array_keys(self::FACTS);
    }
}
