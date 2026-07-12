<?php

namespace App\Repository;

use App\Entity\Line;
use App\Entity\LineDiscussion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LineDiscussion>
 */
class LineDiscussionRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LineDiscussion::class);
    }

    /**
     * Discussions d'une ligne : épinglées d'abord, puis les plus récentes (paginé).
     *
     * @return LineDiscussion[]
     */
    public function findByLineOrdered(Line $line, int $page = 1): array
    {
        return $this->paginate($this->orderedQb()->andWhere('d.line = :line')->setParameter('line', $line), $page);
    }

    public function countByLine(Line $line): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.line = :line')
            ->setParameter('line', $line)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Discussions générales (non rattachées à une ligne), paginé.
     *
     * @return LineDiscussion[]
     */
    public function findGeneralOrdered(int $page = 1): array
    {
        return $this->paginate($this->orderedQb()->andWhere('d.line IS NULL'), $page);
    }

    public function countGeneral(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.line IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de réponses par discussion, en UNE seule requête (évite le N+1).
     *
     * @param int[] $discussionIds
     * @return array<int, int> discussionId => nombre de réponses
     */
    public function replyCounts(array $discussionIds): array
    {
        if ($discussionIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(r.discussion) AS did, COUNT(r.id) AS c
             FROM App\Entity\LineDiscussionReply r
             WHERE r.discussion IN (:ids)
             GROUP BY r.discussion'
        )->setParameter('ids', $discussionIds)->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['did']] = (int) $row['c'];
        }

        return $counts;
    }

    private function orderedQb(): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->addSelect('a')
            ->join('d.author', 'a')
            ->orderBy('d.isPinned', 'DESC')
            ->addOrderBy('d.updatedAt', 'DESC');
    }

    /**
     * @return LineDiscussion[]
     */
    private function paginate(QueryBuilder $qb, int $page): array
    {
        return $qb
            ->setFirstResult((max(1, $page) - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()
            ->getResult();
    }
}
