<?php

namespace App\Twig;

use App\I18n\Translator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtre Twig `|t` pour les modèles d'e-mails : traduit un texte français dans la langue de la
 * requête en cours (celle de l'app qui a déclenché l'envoi). Texte à trous : `'Bienvenue, %s !'|t|format(nom)`.
 */
final class TranslatorExtension extends AbstractExtension
{
    public function __construct(private readonly Translator $translator)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('t', fn (string $french): string => (string) $this->translator->t($french))];
    }
}
