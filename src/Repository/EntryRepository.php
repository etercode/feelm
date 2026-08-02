<?php

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\User;
use App\Entity\Work;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entry>
 */
class EntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    public function findOneByUserAndWork(User $user, Work $work): ?Entry
    {
        return $this->findOneBy(['user' => $user, 'work' => $work]);
    }

    /**
     * @return list<Entry>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int>|null $userIds
     *
     * @return list<Entry>
     */
    public function findActivity(?array $userIds, int $limit = 40, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('e')
            ->innerJoin('e.user', 'u')->addSelect('u')
            ->innerJoin('e.work', 'w')->addSelect('w')
            ->andWhere('u.deletedAt IS NULL')
            ->andWhere('w.deletedAt IS NULL')
            ->orderBy('e.updatedAt', 'DESC')
            // Two entries saved in the same second would otherwise swap places
            // between pages, which shows up as a row appearing twice.
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (null !== $userIds) {
            if ([] === $userIds) {
                return [];
            }
            $qb->andWhere('u.id IN (:ids)')->setParameter('ids', $userIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Counts, in one grouped query.
     *
     * This used to load every entry and tally them in a loop, which also walked
     * into a lazy load of the work behind each one. On a shelf of four thousand
     * that is four thousand queries to render four numbers. Grouping by type
     * and status caps the result at sixteen rows however large the shelf is.
     *
     * @return array{logged: int, finished: int, averageRating: float|null, byType: array<string, int>}
     */
    public function statsForUser(User $user): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select(
                'w.type AS type',
                'e.status AS status',
                'COUNT(e.id) AS n',
                'SUM(e.rating) AS ratingSum',
                'COUNT(e.rating) AS ratingCount',
            )
            ->innerJoin('e.work', 'w')
            ->andWhere('e.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->groupBy('w.type')
            ->addGroupBy('e.status')
            ->getQuery()
            ->getArrayResult();

        $byType = ['movie' => 0, 'series' => 0, 'game' => 0, 'book' => 0];
        $logged = 0;
        $finished = 0;
        $ratingSum = 0.0;
        $ratingCount = 0;

        foreach ($rows as $row) {
            $n = (int) $row['n'];
            $logged += $n;

            $type = (string) $row['type'];
            if (isset($byType[$type])) {
                $byType[$type] += $n;
            }

            if ('done' === $row['status']) {
                $finished += $n;
            }

            $ratingSum += (float) $row['ratingSum'];
            $ratingCount += (int) $row['ratingCount'];
        }

        return [
            'logged' => $logged,
            'finished' => $finished,
            'averageRating' => 0 === $ratingCount ? null : round($ratingSum / $ratingCount, 1),
            'byType' => $byType,
        ];
    }

    /**
     * One page of somebody's shelf, filtered and sorted by the server.
     *
     * The profile page used to receive every entry and do this in the browser,
     * which is fine at thirty and a several-megabyte download at four thousand.
     *
     * @param array{type?: string|null, status?: string|null, q?: string|null, sort?: string|null} $filters
     *
     * @return array{items: list<Entry>, total: int}
     */
    public function pageForUser(User $user, array $filters, int $offset, int $limit): array
    {
        $items = $this->filtered($user, $filters)
            ->addSelect('w')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->sort($items, $filters['sort'] ?? null);

        /** @var list<Entry> $rows */
        $rows = $items->getQuery()->getResult();

        $total = (int) $this->filtered($user, $filters)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * What somebody is in the middle of, newest first.
     *
     * @return list<Entry>
     */
    public function findActiveForUser(User $user, int $limit = 12): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.work', 'w')
            ->addSelect('w')
            ->andWhere('e.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere("e.status = 'active'")
            ->setParameter('user', $user)
            ->orderBy('e.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The work behind a profile's banner: their best-scored thing that actually
     * has artwork wide enough to use.
     */
    public function findBannerWork(User $user): ?Work
    {
        // Selected as an entry and unwrapped, not selected as `w`: DQL will not
        // return a joined alias on its own without the query's root entity.
        $entry = $this->createQueryBuilder('e')
            ->innerJoin('e.work', 'w')
            ->addSelect('w')
            ->andWhere('e.user = :user')
            ->andWhere('e.rating IS NOT NULL')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.backdrop IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('e.rating', 'DESC')
            ->addOrderBy('e.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entry?->getWork();
    }

    /**
     * Titles both people have logged, newest first for the viewer.
     *
     * One query with a self-join, rather than reading both shelves into PHP and
     * intersecting them — that was two full shelves loaded to show a count.
     *
     * @return list<array{mine: Entry, theirs: Entry}>
     */
    public function findShared(User $viewer, User $other, int $limit = 24): array
    {
        /** @var list<array{0: Entry, 1: Entry}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e', 'o')
            ->innerJoin(
                Entry::class,
                'o',
                Join::WITH,
                'o.work = e.work AND o.user = :other',
            )
            ->innerJoin('e.work', 'w')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('e.user = :viewer')
            ->setParameter('viewer', $viewer)
            ->setParameter('other', $other)
            ->orderBy('e.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $pair) => ['mine' => $pair[0], 'theirs' => $pair[1]], $rows);
    }

    /** How many titles two people both have, without fetching any of them. */
    public function countShared(User $viewer, User $other): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->innerJoin(
                Entry::class,
                'o',
                Join::WITH,
                'o.work = e.work AND o.user = :other',
            )
            ->innerJoin('e.work', 'w')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('e.user = :viewer')
            ->setParameter('viewer', $viewer)
            ->setParameter('other', $other)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array{type?: string|null, status?: string|null, q?: string|null, sort?: string|null} $filters
     */
    private function filtered(User $user, array $filters): QueryBuilder
    {
        // The work is joined for every caller, counting included: it is a
        // required relation, so the join costs nothing and it keeps the type
        // and title filters readable.
        $builder = $this->createQueryBuilder('e')
            ->innerJoin('e.work', 'w')
            ->andWhere('e.user = :user')
            // A work the admin has hidden leaves every shelf that held it.
            // The entry stays in the database and comes back if the work is
            // restored — it just stops being something anybody can see.
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('user', $user);

        if (null !== ($filters['type'] ?? null)) {
            $builder->andWhere('w.type = :type')->setParameter('type', $filters['type']);
        }

        if (null !== ($filters['status'] ?? null)) {
            $builder->andWhere('e.status = :status')->setParameter('status', $filters['status']);
        }

        $term = trim((string) ($filters['q'] ?? ''));
        if ('' !== $term) {
            // ILIKE is Postgres, not DQL — lower() both sides instead.
            $builder->andWhere('LOWER(w.title) LIKE :term')
                ->setParameter('term', '%'.mb_strtolower($term).'%');
        }

        return $builder;
    }

    /** Sorting a shelf. Anything unrecognised falls back to most recent. */
    private function sort(QueryBuilder $builder, ?string $sort): void
    {
        match ($sort) {
            // NULLS come first on a DESC in Postgres, so unrated entries would
            // head a "best first" list. Sort the nulls out explicitly.
            'rating' => $builder
                ->addSelect('CASE WHEN e.rating IS NULL THEN 1 ELSE 0 END AS HIDDEN unrated')
                ->orderBy('unrated', 'ASC')
                ->addOrderBy('e.rating', 'DESC'),
            'title' => $builder->orderBy('w.title', 'ASC'),
            'year' => $builder->orderBy('w.year', 'DESC'),
            default => $builder->orderBy('e.updatedAt', 'DESC'),
        };

        // A stable tiebreak, so page 2 cannot repeat a row from page 1.
        $builder->addOrderBy('e.id', 'DESC');
    }
}
