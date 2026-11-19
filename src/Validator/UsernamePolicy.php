<?php

namespace App\Validator;

/**
 * Règle des pseudos, la même qu'à l'inscription sur le site : lettres sans accent, chiffres,
 * tirets et tirets bas, 3 à 20 caractères. Écarte les balises, les espaces et les caractères
 * trompeurs, ainsi que les noms qui se feraient passer pour l'équipe.
 *
 * Appliquée à la création et au changement de pseudo seulement : un ancien pseudo reste valable.
 */
final class UsernamePolicy
{
    public const MIN = 3;
    public const MAX = 20;

    /** Refusés tels quels (chiffres et tirets ignorés) : « support », « staff_1 »… */
    private const RESERVED = ['support', 'staff', 'officiel', 'official', 'system', 'systeme', 'root', 'albs', 'equipe', 'team'];
    /** Refusés dès que le pseudo commence ainsi : « admin », « Admin-Paris », « moderateur2 »… */
    private const RESERVED_PREFIXES = ['admin', 'moderat', 'alabonnestation'];

    /** Le message d'erreur (en français), ou null si le pseudo est acceptable. */
    public static function error(string $username): ?string
    {
        $length = mb_strlen($username);
        if ($length < self::MIN || $length > self::MAX) {
            return 'Le pseudo doit faire entre 3 et 20 caractères.';
        }
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $username) !== 1) {
            return 'Le pseudo ne peut contenir que des lettres sans accent, des chiffres, des tirets et des tirets bas.';
        }

        $bare = preg_replace('/[^a-z]/', '', strtolower($username)) ?? '';
        if (in_array($bare, self::RESERVED, true)) {
            return 'Ce pseudo est réservé.';
        }
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($bare, $prefix)) {
                return 'Ce pseudo est réservé.';
            }
        }

        return null;
    }
}
