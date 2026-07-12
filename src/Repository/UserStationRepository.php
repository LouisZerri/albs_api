<?php

namespace App\Repository;

use App\Entity\UserStation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserStation>
 */
class UserStationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserStation::class);
    }

    /**
     * Progression d'un utilisateur avec la station chargée (eager, évite le N+1 pour les stats).
     *
     * @return UserStation[]
     */
    public function findByUserWithStations(\App\Entity\User $user): array
    {
        return $this->createQueryBuilder('us')
            ->addSelect('s')
            ->join('us.station', 's')
            ->where('us.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}
