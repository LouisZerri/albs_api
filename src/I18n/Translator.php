<?php

namespace App\I18n;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Traduit les textes que l'API envoie à l'app, selon la langue demandée (`Accept-Language`).
 *
 * Le français est la langue d'écriture : chaque texte reste écrit en français là où il est
 * produit (contenu éditorial, messages d'erreur), et sert de CLÉ pour trouver sa traduction —
 * même principe que les traductions de badges du site web. Un texte sans traduction est renvoyé
 * en français : rien ne casse, et `bin/console app:i18n:check` liste ce qui manque.
 *
 * Les noms de stations et de lignes ne passent jamais par ici : ce sont des noms propres.
 */
final class Translator
{
    public const LOCALES = ['fr', 'en'];
    private const DEFAULT_LOCALE = 'fr';

    /** @var array<string, array<string, string>> langue → [texte français => traduction] */
    private const CATALOGUES = ['en' => English::TEXTS];

    public function __construct(private readonly RequestStack $requests)
    {
    }

    /** Langue de la requête en cours (français par défaut, y compris hors requête : commandes, e-mails). */
    public function locale(): string
    {
        $request = $this->requests->getMainRequest();
        if ($request === null || !$request->headers->has('Accept-Language')) {
            return self::DEFAULT_LOCALE;
        }

        return $request->getPreferredLanguage(self::LOCALES) ?? self::DEFAULT_LOCALE;
    }

    /** Traduit un texte fixe. */
    public function t(?string $french): ?string
    {
        if ($french === null) {
            return null;
        }

        return self::CATALOGUES[$this->locale()][$french] ?? $french;
    }

    /** Traduit un texte à trous (`sprintf`) : le modèle est traduit, puis rempli. */
    public function tf(string $frenchFormat, mixed ...$values): string
    {
        return sprintf((string) $this->t($frenchFormat), ...$values);
    }

    /**
     * Traduit un message DÉJÀ rempli (ex. le message d'une exception) : texte fixe d'abord, sinon
     * on retrouve le modèle à trous dont il est issu.
     */
    public function message(string $french): string
    {
        $catalogue = self::CATALOGUES[$this->locale()] ?? [];
        if (isset($catalogue[$french])) {
            return $catalogue[$french];
        }

        foreach ($catalogue as $format => $translated) {
            if (!str_contains($format, '%')) {
                continue;
            }
            $pattern = '/^'.strtr(preg_quote($format, '/'), ['%d' => '(\d+)', '%s' => '(.+)']).'$/u';
            if (preg_match($pattern, $french, $values) === 1) {
                return vsprintf($translated, array_slice($values, 1));
            }
        }

        return $french;
    }

    /** Un texte a-t-il une traduction dans cette langue ? (contrôle de complétude) */
    public static function has(string $locale, string $french): bool
    {
        return $locale === self::DEFAULT_LOCALE || isset(self::CATALOGUES[$locale][$french]);
    }
}
