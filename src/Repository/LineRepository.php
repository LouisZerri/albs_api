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
     * Toutes les lignes avec leurs stations ordonnées (évite le N+1).
     *
     * En DEUX requêtes, exprès : une seule requête avec jointure répéterait le tracé de la ligne
     * (~28 Ko de JSON) sur chacune des 405 stations, soit ~11 Mo à lire pour rien (0,75 s par appel).
     * 1) les 16 lignes, tracé compris, une fois chacune ; 2) les stations, rattachées aux lignes
     * déjà chargées (`PARTIAL l.{id}` : le tracé n'est pas relu).
     *
     * @return Line[]
     */
    public function findAllWithStations(): array
    {
        $lines = $this->findBy([], ['id' => 'ASC']);

        $this->createQueryBuilder('l')
            ->select('PARTIAL l.{id}', 's')
            ->leftJoin('l.stations', 's')
            ->orderBy('l.id', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $lines;
    }
}
