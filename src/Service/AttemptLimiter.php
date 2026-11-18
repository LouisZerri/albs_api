<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Limite le nombre d'essais RATÉS sur une action sensible (connexion, code de vérification, code
 * de réinitialisation) pour un même compte : sans ça, un code à 6 chiffres ou un mot de passe se
 * devine par essais successifs.
 *
 * Compteur tenu dans le cache de l'application (simple, sans dépendance, suffisant pour un seul
 * serveur). La fenêtre démarre au premier échec ; un succès remet le compteur à zéro.
 */
final class AttemptLimiter
{
    public const MESSAGE = 'Trop d\'essais. Réessaie dans quelques minutes.';

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    /** Le nombre d'échecs autorisés est-il déjà atteint ? */
    public function isBlocked(string $scope, string $subject, int $maxFailures): bool
    {
        return (int) ($this->cache->getItem($this->key($scope, $subject))->get() ?? 0) >= $maxFailures;
    }

    public function recordFailure(string $scope, string $subject, int $windowSeconds): void
    {
        $item = $this->cache->getItem($this->key($scope, $subject));
        $failures = (int) ($item->get() ?? 0);
        $item->set($failures + 1);
        if ($failures === 0) {
            $item->expiresAfter($windowSeconds); // la fenêtre court depuis le premier échec
        }
        $this->cache->save($item);
    }

    public function clear(string $scope, string $subject): void
    {
        $this->cache->deleteItem($this->key($scope, $subject));
    }

    /** Clé de cache sûre : le sujet (une adresse e-mail) est haché, sans casse ni espaces. */
    private function key(string $scope, string $subject): string
    {
        return 'attempts_'.$scope.'_'.hash('xxh128', mb_strtolower(trim($subject)));
    }
}
