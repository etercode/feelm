<?php

namespace App\Repository;

use App\Entity\Work;
use App\Entity\Review;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    public function findOneByUserAndWork(User $user, Work $work): ?Review
    {
        return $this->findOneBy(['user' => $user, 'work' => $work]);
    }

    /**
     * The reviews behind a page of activity, in one query.
     *
     * The feed asks "did this person write about this title" once per row, and
     * forty rows meant forty round trips before anything could be rendered.
     * Both id lists are already in hand by then, so this fetches the whole
     * rectangle and the caller picks out the pairs it wanted — a few rows of
     * overfetch against thirty-nine queries saved.
     *
     * Keyed "userId:workId" because a review is identified by the pair; the
     * caller cannot index it any other way without doing this work again.
     *
     * @param list<int> $userIds
     * @param list<int> $workIds
     *
     * @return array<string, Review>
     */
    public function mapForPairs(array $userIds, array $workIds): array
    {
        if ([] === $userIds || [] === $workIds) {
            return [];
        }

        /** @var list<Review> $rows */
        $rows = $this->createQueryBuilder('r')
            ->innerJoin('r.user', 'u')->addSelect('u')
            ->innerJoin('r.work', 'w')->addSelect('w')
            ->andWhere('u.id IN (:users)')
            ->andWhere('w.id IN (:works)')
            ->setParameter('users', array_values(array_unique($userIds)))
            ->setParameter('works', array_values(array_unique($workIds)))
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $review) {
            $map[$review->getUser()?->getId().':'.$review->getWork()?->getId()] = $review;
        }

        return $map;
    }

    /**
     * Reviews of one title.
     *
     * No deleted_at check on the work: it arrives as a parameter, and the only
     * public route to one is findOneByTypeAndSlug, which already refuses hidden
     * rows. There is no `w` alias here to test anyway.
     *
     * @return list<Review>
     */
    public function findByWork(Work $work): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.user', 'u')->addSelect('u')
            ->andWhere('r.work = :work')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('work', $work)
            ->orderBy('r.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Review>
     */
    public function findByUser(User $user, ?int $limit = null): array
    {
        $builder = $this->createQueryBuilder('r')
            ->innerJoin('r.work', 'w')->addSelect('w')
            ->andWhere('r.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('r.updatedAt', 'DESC');

        if (null !== $limit) {
            $builder->setMaxResults($limit);
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * @return array{average: float|null, count: int}
     */
    public function ratingOf(Work $work): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) AS average', 'COUNT(r.id) AS cnt')
            ->andWhere('r.work = :work')
            ->setParameter('work', $work)
            ->getQuery()
            ->getSingleResult();

        $count = (int) $row['cnt'];

        return [
            'average' => 0 === $count ? null : round((float) $row['average'], 1),
            'count' => $count,
        ];
    }

    public function countByUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    /**
     * The latest reviews written anywhere, for the admin overview.
     *
     * Author and work are joined in: the presenter reaches for both, and six
     * rows would otherwise be thirteen queries.
     *
     * @return list<Review>
     */
    public function findRecent(int $limit = 6): array
    {
        /** @var list<Review> $reviews */
        $reviews = $this->createQueryBuilder('r')
            ->innerJoin('r.user', 'u')->addSelect('u')
            ->innerJoin('r.work', 'w')->addSelect('w')
            ->andWhere('w.deletedAt IS NULL')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $reviews;
    }

    /* -------------------------------------------------------------- admin */

    /**
     * One page of reviews for the admin table.
     *
     * @param array{q?: string|null, user?: string|null, type?: string|null, rating?: string|null, edited?: string|null, sort?: string|null} $filters
     *
     * @return array{items: list<Review>, total: int}
     */
    public function page(array $filters, int $offset, int $limit): array
    {
        $items = $this->filtered($filters)
            ->addSelect('u', 'w')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->sort($items, $filters['sort'] ?? null);

        /** @var list<Review> $rows */
        $rows = $items->getQuery()->getResult();

        $total = (int) $this->filtered($filters)
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * @param array{q?: string|null, user?: string|null, type?: string|null, rating?: string|null, edited?: string|null, sort?: string|null} $filters
     */
    private function filtered(array $filters): QueryBuilder
    {
        // Both relations are required, so the joins cost nothing and every
        // filter below can reach the author and the title.
        $builder = $this->createQueryBuilder('r')
            ->innerJoin('r.user', 'u')
            ->innerJoin('r.work', 'w');

        $term = trim((string) ($filters['q'] ?? ''));
        if ('' !== $term) {
            /*
             * The body is unindexed TEXT, so this is a sequential scan. It is
             * the right trade at the size a review table reaches: moderation
             * means finding the one sentence somebody complained about, and an
             * index that only serves the admin is not worth writing on every
             * review anybody posts.
             */
            $builder
                ->andWhere('LOWER(r.body) LIKE :term OR LOWER(w.title) LIKE :term OR LOWER(u.username) LIKE :term OR LOWER(u.name) LIKE :term')
                ->setParameter('term', '%'.mb_strtolower($term).'%');
        }

        if (null !== ($filters['user'] ?? null)) {
            $builder->andWhere('u.username = :author')->setParameter('author', $filters['user']);
        }

        if (null !== ($filters['type'] ?? null)) {
            $builder->andWhere('w.type = :type')->setParameter('type', $filters['type']);
        }

        // Ratings are stored as DECIMAL, so the bound is a string comparison in
        // the driver either way; casting here keeps the intent obvious.
        $rating = $filters['rating'] ?? null;
        if (null !== $rating) {
            match ($rating) {
                'low' => $builder->andWhere('r.rating <= 2'),
                'mid' => $builder->andWhere('r.rating > 2 AND r.rating < 4'),
                'high' => $builder->andWhere('r.rating >= 4'),
                default => $builder,
            };
        }

        // "Edited" means a previous version was kept — the audit trail exists.
        if (null !== ($filters['edited'] ?? null)) {
            $exists = 'EXISTS (SELECT 1 FROM App\Entity\ReviewVersion rv WHERE rv.review = r)';
            $builder->andWhere('yes' === $filters['edited'] ? $exists : 'NOT '.$exists);
        }

        return $builder;
    }

    /**
     * How many stored versions each of these reviews has, in one query.
     *
     * The alternative is asking each Review for its versions collection, which
     * is lazy — a page of twenty-five was twenty-five extra round trips just to
     * put "edited" in a column.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    public function versionCountsFor(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT review_id AS id, COUNT(*) AS n FROM review_versions WHERE review_id IN (?) GROUP BY review_id',
            [$ids],
            [ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $counts = array_fill_keys($ids, 0);
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        return $counts;
    }

    /** Sorting reviews. Anything unrecognised falls back to newest. */
    private function sort(QueryBuilder $builder, ?string $sort): void
    {
        match ($sort) {
            'oldest' => $builder->orderBy('r.createdAt', 'ASC'),
            'updated' => $builder->orderBy('r.updatedAt', 'DESC'),
            'rating' => $builder->orderBy('r.rating', 'DESC'),
            'lowest' => $builder->orderBy('r.rating', 'ASC'),
            default => $builder->orderBy('r.createdAt', 'DESC'),
        };

        // A stable tiebreak, so page 2 cannot repeat a row from page 1.
        $builder->addOrderBy('r.id', 'DESC');
    }
}
