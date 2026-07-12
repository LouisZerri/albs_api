<?php

namespace App\Repository;

use App\Entity\Station;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Station>
 */
class StationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Station::class);
    }

    /**
     * Les autres quais de la même station physique (même nom, autres lignes).
     * Sert la règle « descendu = tous les quais du hub » et les correspondances.
     *
     * @return Station[]
     */
    public function findSiblings(Station $station): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.name = :name')
            ->andWhere('s.id != :id')
            ->setParameter('name', $station->getName())
            ->setParameter('id', $station->getId())
            ->getQuery()
            ->getResult();
    }
}
