<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Avertissement de modération. Mappé sur la table `warning` de la base partagée
 * (créée/gérée côté web) — on n'expose ici que ce dont l'app mobile a besoin.
 */
#[ORM\Entity]
#[ORM\Table(name: 'warning')]
class Warning
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $reason = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAcknowledgedAt(): ?\DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }

    public function setAcknowledgedAt(?\DateTimeImmutable $acknowledgedAt): static
    {
        $this->acknowledgedAt = $acknowledgedAt;
        return $this;
    }
}
