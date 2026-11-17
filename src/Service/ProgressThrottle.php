<?php

namespace App\Service;

use App\Entity\User;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Garde-fou sur le nombre de cochages par utilisateur (anti-martelage, script abusif).
 *
 * Le plafond est volontairement LARGE : au retour du réseau, l'app envoie d'un coup tous les
 * cochages faits sous terre, et cet usage normal ne doit jamais être freiné. Au-delà, le serveur
 * répond 429 ; l'app garde alors le cochage en file et le renvoie un peu plus tard (rien n'est perdu).
 *
 * Compteur par minute, tenu dans le cache de l'application : simple, sans dépendance, suffisant
 * pour un seul serveur.
 */
final class ProgressThrottle
{
    private const MAX_PER_MINUTE = 300;
    private const WINDOW_SECONDS = 60;

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    /** @throws TooManyRequestsHttpException si l'utilisateur a dépassé le plafond de la minute en cours */
    public function hit(User $user): void
    {
        $now = time();
        $window = intdiv($now, self::WINDOW_SECONDS);
        $item = $this->cache->getItem(sprintf('progress_throttle_%d_%d', $user->getId(), $window));

        $count = (int) ($item->get() ?? 0) + 1;
        if ($count > self::MAX_PER_MINUTE) {
            $retryAfter = ($window + 1) * self::WINDOW_SECONDS - $now;

            throw new TooManyRequestsHttpException($retryAfter, 'Trop de cochages d\'un coup. Réessaie dans un instant.');
        }

        $item->set($count)->expiresAfter(self::WINDOW_SECONDS * 2);
        $this->cache->save($item);
    }
}
