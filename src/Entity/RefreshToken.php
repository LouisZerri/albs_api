<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

/**
 * Entité concrète des refresh tokens (le bundle ne fournit qu'un mapped-superclass).
 * Table `refresh_tokens` — additive, propre à l'API (le web ne l'utilise pas).
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_tokens')]
class RefreshToken extends BaseRefreshToken
{
}
