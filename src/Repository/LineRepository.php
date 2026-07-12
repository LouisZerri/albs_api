<?php

namespace App\Repository;

use App\Entity\Line;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Line>
 */
class LineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Line::class);
    }

    /**
     * Toutes les lignes avec leurs stations ordonnées (eager, évite le N+1 pour les stats).
     *
     * @return Line[]
     */
    public function findAllWithStations(): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('s')
            ->leftJoin('l.stations', 's')
            ->orderBy('l.id', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
