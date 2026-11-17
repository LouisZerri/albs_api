<?php

namespace App\Repository;

use App\Entity\Bonplan;
use App\Entity\BonplanFavorite;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BonplanFavorite>
 */
class BonplanFavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BonplanFavorite::class);
    }

    public function findOneByUserAndBonplan(User $user, Bonplan $bonplan): ?BonplanFavorite
    {
        return $this->findOneBy(['user' => $user, 'bonplan' => $bonplan]);
    }

    /**
     * Favoris de l'utilisateur avec bonplan + station + ligne chargés (eager).
     *
     * @return BonplanFavorite[]
     */
    public function findByUserWithDetails(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->addSelect('b', 's', 'l')
            ->join('f.bonplan', 'b')
            ->join('b.station', 's')
            ->join('s.line', 'l')
            ->andWhere('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ids des bons plans mis en favori par l'utilisateur parmi ceux d'un ensemble de quais.
     *
     * @param \App\Entity\Station[] $stations
     *
     * @return int[]
     */
    public function favoritedBonplanIdsForStations(User $user, array $stations): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.bonplan) AS bid')
            ->join('f.bonplan', 'b')
            ->andWhere('f.user = :user')
            ->andWhere('b.station IN (:stations)')
            ->setParameter('user', $user)
            ->setParameter('stations', $stations)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn ($r) => (int) $r['bid'], $rows);
    }
}
