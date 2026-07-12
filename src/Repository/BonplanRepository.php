<?php

namespace App\Repository;

use App\Entity\Bonplan;
use App\Entity\Station;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bonplan>
 */
class BonplanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bonplan::class);
    }

    /**
     * Bons plans approuvés d'une station (triés par catégorie puis titre).
     *
     * @return Bonplan[]
     */
    public function findApprovedForStation(Station $station): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.station = :station')
            ->andWhere('b.status = :status')
            ->setParameter('station', $station)
            ->setParameter('status', Bonplan::STATUS_APPROVED)
            ->orderBy('b.category', 'ASC')
            ->addOrderBy('b.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
