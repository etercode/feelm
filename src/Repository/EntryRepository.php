<?php

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\User;
use App\Entity\Work;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
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
     * Somebody's whole shelf as plain rows: what is on it and in what state,
     * and nothing about the titles themselves.
     *
     * The browser keeps this so any poster anywhere can show whether it is on
     * your shelf, which means it genuinely does have to be all of them — there
     * is no page of it that would answer the question. What it does not need is
     * the titles: every screen that draws one is given it by whatever endpoint
     * filled that screen.
     *
     * Array hydration, and the work is joined but never selected. Building
     * three thousand Entry objects each holding a fully hydrated Work is what
     * exhausted 256MB on production; these are arrays of six scalars.
     *
     * @return list<array{id: int, itemId: int, status: string, rating: string|null, progress: array<string, mixed>|null, updatedAt: \DateTimeInterface}>
     */
    public function shelfStateForUser(User $user): array
    {
        /** @var list<array{id: int, itemId: int, status: string, rating: string|null, progress: array<string, mixed>|null, updatedAt: \DateTimeInterface}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select(
                'e.id AS id',
                'IDENTITY(e.work) AS itemId',
                'e.status AS status',
                'e.rating AS rating',
                'e.progress AS progress',
                'e.updatedAt AS updatedAt',
            )
            ->innerJoin('e.work', 'w')
            ->andWhere('e.user = :user')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('e.updatedAt', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /**
     * Just the work ids somebody has on a shelf.
     *
     * shelfStateForUser() answers the same question with six columns and a
     * sort, which is the right shape for the library and the wrong one for a
     * caller that only wants to subtract a set — recommending a film somebody
     * has already logged is the recommendation nobody needed.
     *
     * @return list<int>
     */
    public function shelvedWorkIds(User $user): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT work_id FROM entries WHERE user_id = :user',
            ['user' => $user->getId()],
        )->fetchFirstColumn());
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
     * Fun facts about a shelf: how much time is on it, and its extremes.
     *
     * ---- why there is no chart over time -----------------------------------
     *
     * There was one, and it was wrong. It grouped finished titles by
     * `updated_at` and called them months of watching — but somebody who joins
     * today and adds the three thousand films they have seen over twenty years
     * logs all three thousand today. The dates in this table say when a row was
     * written, not when a film was watched, and nothing in the schema knows the
     * difference. A chart built on that reports how somebody used the app,
     * dressed up as how they spend their life.
     *
     * Totals do not have that problem. "Nine years of screen time" is true
     * whether it was logged over a decade or in one sitting.
     *
     * ---- how series time is worked out -------------------------------------
     *
     * A film carries its own runtime. A series does not: it has an episode
     * count from the source and a table of episodes that the crawler fills in
     * over time, so the episode rows are a sample rather than the whole show —
     * 73,000 rows against an authoritative 160,000 for this shelf.
     *
     * So the count comes from the source and the length from the sample: each
     * show's own episodes, averaged, times how many episodes it actually has.
     * An episode of a show is the length of that show's other episodes, which
     * makes this an estimate but not a guess. Shows with no runtime anywhere
     * contribute nothing and are counted in `seriesUnknown`, so the caller can
     * say the total is a floor rather than implying it is exact.
     *
     * @return array{
     *     filmMinutes: int, filmCount: int,
     *     seriesMinutes: int, seriesCount: int, episodes: int, seriesUnknown: int,
     *     longest: array{title: string, slug: string, type: string, minutes: int}|null,
     *     oldest: array{title: string, slug: string, type: string, year: int}|null,
     *     decade: array{decade: int, count: int}|null
     * }
     */
    public function highlightsForUser(User $user): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $id = $user->getId();

        $films = $connection->executeQuery(
            <<<'SQL'
                SELECT COUNT(*) AS n, COALESCE(SUM(w.runtime_minutes), 0) AS minutes
                FROM entries e
                JOIN works w ON w.id = e.work_id
                WHERE e.user_id = :user AND e.status = 'done'
                  AND w.type = 'movie' AND w.deleted_at IS NULL
                SQL,
            ['user' => $id],
        )->fetchAssociative() ?: [];

        $series = $connection->executeQuery(
            <<<'SQL'
                WITH shows AS (
                    SELECT w.id,
                           NULLIF(w.extra ->> 'episodeCount', '')::int   AS episodes,
                           AVG(ep.runtime)                               AS sampled,
                           NULLIF(w.extra ->> 'episodeRuntime', '')::int AS declared
                    FROM entries e
                    JOIN works w ON w.id = e.work_id
                    LEFT JOIN seasons s ON s.work_id = w.id
                    LEFT JOIN episodes ep ON ep.season_id = s.id
                    WHERE e.user_id = :user AND e.status = 'done'
                      AND w.type = 'series' AND w.deleted_at IS NULL
                    GROUP BY w.id
                )
                SELECT COUNT(*) AS n,
                       COALESCE(SUM(episodes), 0) AS episodes,
                       COALESCE(SUM(episodes * COALESCE(sampled, declared)), 0) AS minutes,
                       COUNT(*) FILTER (WHERE COALESCE(sampled, declared) IS NULL) AS unknown
                FROM shows
                SQL,
            ['user' => $id],
        )->fetchAssociative() ?: [];

        return [
            'filmMinutes' => (int) ($films['minutes'] ?? 0),
            'filmCount' => (int) ($films['n'] ?? 0),
            'seriesMinutes' => (int) round((float) ($series['minutes'] ?? 0)),
            'seriesCount' => (int) ($series['n'] ?? 0),
            'episodes' => (int) ($series['episodes'] ?? 0),
            'seriesUnknown' => (int) ($series['unknown'] ?? 0),
            'longest' => $this->record($connection, $id, 'longest'),
            'oldest' => $this->record($connection, $id, 'oldest'),
            'decade' => $this->decade($connection, $id),
        ];
    }

    /**
     * The longest thing somebody sat through, or the oldest thing they have
     * seen. Two shapes of the same query, kept together so the join and the
     * filters cannot drift apart.
     *
     * @return array<string, mixed>|null
     */
    private function record(Connection $connection, int $userId, string $which): ?array
    {
        $order = 'longest' === $which ? 'w.runtime_minutes DESC' : 'w.year ASC';
        $needs = 'longest' === $which
            ? "w.type = 'movie' AND w.runtime_minutes > 0"
            : 'w.year IS NOT NULL';

        $row = $connection->executeQuery(
            <<<SQL
                SELECT w.title, w.slug, w.type, w.year, w.runtime_minutes
                FROM entries e
                JOIN works w ON w.id = e.work_id
                WHERE e.user_id = :user AND e.status = 'done'
                  AND w.deleted_at IS NULL AND {$needs}
                ORDER BY {$order}, w.id ASC
                LIMIT 1
                SQL,
            ['user' => $userId],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return [
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
            'type' => (string) $row['type'],
            'year' => null === $row['year'] ? null : (int) $row['year'],
            'minutes' => null === $row['runtime_minutes'] ? null : (int) $row['runtime_minutes'],
        ];
    }

    /** @return array{decade: int, count: int}|null */
    private function decade(Connection $connection, int $userId): ?array
    {
        $row = $connection->executeQuery(
            <<<'SQL'
                SELECT (w.year / 10) * 10 AS decade, COUNT(*) AS n
                FROM entries e
                JOIN works w ON w.id = e.work_id
                WHERE e.user_id = :user AND e.status = 'done'
                  AND w.year IS NOT NULL AND w.deleted_at IS NULL
                GROUP BY 1
                ORDER BY n DESC, decade DESC
                LIMIT 1
                SQL,
            ['user' => $userId],
        )->fetchAssociative();

        return false === $row ? null : ['decade' => (int) $row['decade'], 'count' => (int) $row['n']];
    }

    /**
     * What somebody's scores look like, and what they actually spend time on.
     *
     * Two grouped queries rather than a page of entries. The histogram over
     * three thousand finished titles is a count, and sending three thousand
     * rows to the browser so it can count them there is exactly what splitting
     * the shelf endpoint out was meant to stop.
     *
     * Genres are counted over finished titles only: a wishlist says what
     * somebody means to watch, and this is a summary of what they did watch.
     *
     * @return array{ratings: array<string, int>, genres: list<array{name: string, count: int}>}
     */
    public function tasteForUser(User $user, int $genreLimit = 8): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.rating AS rating', 'COUNT(e.id) AS n')
            ->innerJoin('e.work', 'w')
            ->andWhere('e.user = :user')
            ->andWhere('e.rating IS NOT NULL')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->groupBy('e.rating')
            ->getQuery()
            ->getArrayResult();

        // Every half-step present, including the ones nobody used. A histogram
        // that omits its empty columns reads as "no data down here" rather than
        // "this person never scores anything this low", which is the finding.
        $ratings = [];
        for ($step = 1; $step <= 10; ++$step) {
            $ratings[number_format($step / 2, 1, '.', '')] = 0;
        }

        foreach ($rows as $row) {
            $key = number_format((float) $row['rating'], 1, '.', '');
            if (isset($ratings[$key])) {
                $ratings[$key] = (int) $row['n'];
            }
        }

        /** @var list<array<string, mixed>> $genreRows */
        $genreRows = $this->createQueryBuilder('e')
            ->select('g.name AS name', 'COUNT(e.id) AS n')
            ->innerJoin('e.work', 'w')
            ->innerJoin('w.genres', 'g')
            ->andWhere('e.user = :user')
            ->andWhere('e.status = :done')
            ->andWhere('w.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('done', 'done')
            ->groupBy('g.name')
            ->orderBy('n', 'DESC')
            // Alphabetical inside a tie, so the list does not reshuffle itself
            // between two requests that counted the same.
            ->addOrderBy('g.name', 'ASC')
            ->setMaxResults($genreLimit)
            ->getQuery()
            ->getArrayResult();

        return [
            'ratings' => $ratings,
            'genres' => array_map(
                static fn (array $row) => ['name' => (string) $row['name'], 'count' => (int) $row['n']],
                $genreRows,
            ),
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
