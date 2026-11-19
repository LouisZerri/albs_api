<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Invalide les jetons d'accès (JWT) déjà délivrés à un compte.
 *
 * Un JWT reste valable jusqu'à son expiration (1 h), même après une réinitialisation de mot de
 * passe. On note donc l'instant de la réinitialisation, et tout jeton émis avant est refusé.
 * La note vit dans le cache le temps de vie d'un jeton : au-delà, ils ont tous expiré d'eux-mêmes.
 */
final class SessionRevoker
{
    /** Durée de vie d'un JWT (Lexik, valeur par défaut). */
    private const TOKEN_TTL_SECONDS = 3600;

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function revokeAll(string $email): void
    {
        $item = $this->cache->getItem($this->key($email));
        $item->set(time());
        $item->expiresAfter(self::TOKEN_TTL_SECONDS);
        $this->cache->save($item);
    }

    /** Ce jeton a-t-il été émis avant la dernière invalidation ? */
    public function isRevoked(string $email, int $issuedAt): bool
    {
        $revokedAt = $this->cache->getItem($this->key($email))->get();

        return is_int($revokedAt) && $issuedAt < $revokedAt;
    }

    private function key(string $email): string
    {
        return 'jwt_revoked_'.hash('xxh128', mb_strtolower(trim($email)));
    }
}
