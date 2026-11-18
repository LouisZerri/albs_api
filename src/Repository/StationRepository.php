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

    /**
     * Tous les quais d'une station physique (même nom exact), le quai donné compris.
     *
     * @return Station[]
     */
    public function findPlatforms(Station $station): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.line', 'l')->addSelect('l')
            ->andWhere('s.name = :name')
            ->setParameter('name', $station->getName())
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Nombre de stations réelles : plusieurs quais de même nom (correspondances) = une station. */
    public function countPhysical(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(DISTINCT s.name)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Les lignes desservant chaque station, par nom de station (ordre des lignes, sans doublon).
     *
     * @return array<string, list<array{lineId: int, number: string, color: string, textColor: ?string}>>
     */
    public function linesByStationName(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.name AS name, l.id AS lineId, l.number AS number, l.color AS color, l.textColor AS textColor')
            ->join('s.line', 'l')
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $r) {
            $map[$r['name']][] = [
                'lineId' => (int) $r['lineId'],
                'number' => $r['number'],
                'color' => $r['color'],
                'textColor' => $r['textColor'],
            ];
        }

        return $map;
    }

    /**
     * Un quai représentatif par station physique (nom de station => plus petit id), pour ouvrir
     * l'écran d'une station à partir de son nom.
     *
     * @return array<string, int>
     */
    public function representativeIdsByName(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.name AS name, MIN(s.id) AS id')
            ->groupBy('s.name')
            ->getQuery()
            ->getArrayResult();

        return array_map('intval', array_column($rows, 'id', 'name'));
    }
}
