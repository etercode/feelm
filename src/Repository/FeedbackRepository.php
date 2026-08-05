<?php

namespace App\Repository;

use App\Entity\Feedback;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Feedback>
 */
class FeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feedback::class);
    }

    /**
     * One person's own reports, newest first.
     *
     * @return array{items: list<Feedback>, total: int}
     */
    public function pageForUser(User $user, int $offset, int $limit): array
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.user = :user')
            ->setParameter('user', $user);

        $total = (int) (clone $qb)->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();

        $items = $qb
            ->orderBy('f.createdAt', 'DESC')
            // Ties are common — two reports filed in the same second while
            // someone works through a page — and a pager that cannot break them
            // consistently shows the same row twice.
            ->addOrderBy('f.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * The administrator's queue.
     *
     * Sorted with the undecided first by default, because the only question
     * this list exists to answer is "what have I not looked at yet".
     *
     * @param array{status?: string|null, category?: string|null, q?: string|null, sort?: string|null} $filters
     *
     * @return array{items: list<Feedback>, total: int}
     */
    public function page(array $filters, int $offset, int $limit): array
    {
        $qb = $this->createQueryBuilder('f')->innerJoin('f.user', 'u')->addSelect('u');

        if (!empty($filters['status'])) {
            $qb->andWhere('f.status = :status')->setParameter('status', $filters['status']);
        }

        if (!empty($filters['category'])) {
            $qb->andWhere('f.category = :category')->setParameter('category', $filters['category']);
        }

        if (!empty($filters['q'])) {
            $qb->andWhere('LOWER(f.body) LIKE :q OR LOWER(u.username) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim((string) $filters['q'])).'%');
        }

        $total = (int) (clone $qb)->select('COUNT(DISTINCT f.id)')->getQuery()->getSingleScalarResult();

        if ('oldest' === ($filters['sort'] ?? null)) {
            $qb->orderBy('f.createdAt', 'ASC');
        } elseif ('recent' === ($filters['sort'] ?? null)) {
            $qb->orderBy('f.createdAt', 'DESC');
        } else {
            // Undecided first, then newest. A CASE rather than a status sort,
            // because alphabetical order on the status column puts "accepted"
            // above "new", which is precisely backwards.
            $qb->addSelect("CASE WHEN f.status = 'new' THEN 0 WHEN f.status = 'accepted' THEN 1 ELSE 2 END AS HIDDEN rank")
                ->orderBy('rank', 'ASC')
                ->addOrderBy('f.createdAt', 'DESC');
        }

        $items = $qb
            ->addOrderBy('f.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * How many sit in each status, for the badge on the admin menu.
     *
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('f.status AS status', 'COUNT(f.id) AS total')
            ->groupBy('f.status')
            ->getQuery()
            ->getArrayResult();

        $out = array_fill_keys(Feedback::STATUSES, 0);
        foreach ($rows as $row) {
            $out[$row['status']] = (int) $row['total'];
        }

        return $out;
    }
}
