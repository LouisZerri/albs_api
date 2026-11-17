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
     * Bons plans approuvés d'un ensemble de quais (triés par catégorie puis titre).
     *
     * @param Station[] $stations
     *
     * @return Bonplan[]
     */
    public function findApprovedForStations(array $stations): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.submittedBy', 'u')->addSelect('u') // auteur affiché → évite un N+1
            ->andWhere('b.station IN (:stations)')
            ->andWhere('b.status = :status')
            ->setParameter('stations', $stations)
            ->setParameter('status', Bonplan::STATUS_APPROVED)
            ->orderBy('b.category', 'ASC')
            ->addOrderBy('b.title', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Stations physiques (regroupées par nom) ayant au moins un bon plan approuvé, triées par nom.
     * Un même lieu OpenStreetMap importé sur plusieurs quais n'est compté qu'une fois.
     *
     * @return list<array{id: int, name: string, total: int}> `id` = un quai représentatif
     */
    public function stationsWithCounts(): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select("s.name AS name, MIN(s.id) AS id, COUNT(DISTINCT COALESCE(b.osmId, CONCAT('#', b.id))) AS total")
            ->join('b.station', 's')
            ->andWhere('b.status = :status')
            ->setParameter('status', Bonplan::STATUS_APPROVED)
            ->groupBy('s.name')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'total' => (int) $r['total'],
        ], $rows);
    }

    /**
     * Nombre de bons plans approuvés par nom de station (lieux distincts). Stations sans bon plan absentes.
     *
     * @return array<string, int>
     */
    public function countByStationName(): array
    {
        return array_column($this->stationsWithCounts(), 'total', 'name');
    }
}
