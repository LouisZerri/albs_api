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

    private const SEQUENCE_LENGTH = 4;
    /** Liste du site (`NoInappropriateWordsValidator::FORBIDDEN_WORDS`), cherchée n'importe où dans le pseudo. */
    private const FORBIDDEN_WORDS = [
        'admin', 'administrator', 'moderator', 'moderateur', 'root', 'system', 'support', 'test',
        'caca', 'pipi', 'merde', 'connard', 'salope', 'putain', 'pute', 'nazi', 'hitler', 'fuck',
        'shit', 'ass', 'bitch', 'dick', 'penis', 'vagina', 'sex', 'porn', 'xxx', 'fdp', 'ntm',
        'enculé', 'encule', 'bite', 'couille', 'chatte', 'con', 'connasse', 'salaud', 'batard',
    ];
    private const KEYBOARD_SEQUENCES = ['azerty', 'qwerty', 'azertyuiop', 'qwertyuiop', 'asdfgh', 'zxcvbn', '123456', 'abcdef', '098765'];
    /** Lettres et chiffres qui se ressemblent : « 4dm1n » est lu comme « admin ». */
    private const LOOKALIKES = ['a' => '[a@4]', 'e' => '[e3]', 'i' => '[i1!]', 'o' => '[o0]', 's' => '[s5$]', 't' => '[t7]', 'l' => '[l1]', 'g' => '[g9]', 'b' => '[b8]'];

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

        return self::inappropriateError(strtolower($username));
    }

    /**
     * Les contrôles du site (`NoInappropriateWordsValidator`), recopiés à l'identique : mots
     * interdits et leurs variantes chiffrées, répétitions, suites de clavier, suites logiques.
     * ⚠️ Toute retouche est à faire des deux côtés.
     */
    private static function inappropriateError(string $lower): ?string
    {
        foreach (self::FORBIDDEN_WORDS as $word) {
            if (preg_match('/'.strtr($word, self::LOOKALIKES).'/i', $lower) === 1) {
                return 'Ce pseudo n\'est pas valide';
            }
        }
        if (preg_match('/(.)\1{2,}/', $lower) === 1) {
            return 'Le pseudo ne peut pas contenir plus de 2 caractères identiques consécutifs (ex: aaa, zzz).';
        }
        if (preg_match('/(.{2,})\1{2,}/', $lower) === 1) {
            return 'Le pseudo ne peut pas contenir de motifs répétitifs (ex: ababab, 123123).';
        }
        foreach (self::KEYBOARD_SEQUENCES as $sequence) {
            if (str_contains($lower, $sequence)) {
                return 'Le pseudo ne peut pas contenir de suites de clavier (ex: azerty, qwerty).';
            }
        }
        if (self::hasSequentialChars($lower)) {
            return 'Le pseudo ne peut pas contenir de suites logiques (ex: abc, 123, 987).';
        }

        return null;
    }

    /** Quatre caractères qui se suivent, dans un sens ou dans l'autre (abcd, 1234, 9876). */
    private static function hasSequentialChars(string $value): bool
    {
        for ($i = 0; $i + self::SEQUENCE_LENGTH <= strlen($value); ++$i) {
            $ascending = $descending = true;
            for ($j = 0; $j < self::SEQUENCE_LENGTH - 1; ++$j) {
                $step = ord($value[$i + $j + 1]) - ord($value[$i + $j]);
                $ascending = $ascending && $step === 1;
                $descending = $descending && $step === -1;
            }
            if ($ascending || $descending) {
                return true;
            }
        }

        return false;
    }
}
