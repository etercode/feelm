<?php

namespace App\Repository;

use App\Entity\ExternalId;
use App\Entity\Work;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lookups and small aggregates. Anything with filters or free text goes through
 * App\Search\WorkSearch instead — one place owns the query building.
 *
 * @extends ServiceEntityRepository<Work>
 */
class WorkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Work::class);
    }

    /**
     * The public detail page. Hidden works 404 here, like anything else that
     * is not in the catalog.
     *
     * `$includeDeleted` is for the admin, which is the one caller allowed to
     * look at a work it has hidden.
     */
    public function findOneByTypeAndSlug(string $type, string $slug, bool $includeDeleted = false): ?Work
    {
        $builder = $this->createQueryBuilder('w')
            ->addSelect('g', 'r', 'x')
            ->leftJoin('w.genres', 'g')
            ->leftJoin('w.ratings', 'r')
            ->leftJoin('w.externalIds', 'x')
            ->andWhere('w.type = :type')
            ->andWhere('w.slug = :slug')
            ->setParameter('type', $type)
            ->setParameter('slug', $slug);

        if (!$includeDeleted) {
            $builder->andWhere('w.deletedAt IS NULL');
        }

        return $builder->getQuery()->getOneOrNullResult();
    }

    /**
     * The only correct way to find a crawled row again.
     *
     * Deliberately finds hidden works too, and this is not an oversight: the
     * crawler asks this question to decide whether it has seen a title before.
     * Hide a work from it and the next pass concludes the title is missing,
     * inserts it again, and dies on the unique index over
     * external_ids (source, external_id). A hidden work must stay findable by
     * identity — hiding is about the catalog, not about what exists.
     */
    public function findOneByExternalId(string $source, string $externalId): ?Work
    {
        return $this->createQueryBuilder('w')
            ->join('w.externalIds', 'x')
            ->andWhere('x.source = :source')
            ->andWhere('x.externalId = :externalId')
            ->setParameter('source', $source)
            ->setParameter('externalId', $externalId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The work TMDB gave this id — in the id space of the type being asked
     * for. TMDB numbers films and television apart, so the type is not
     * decoration: without it, series 1396 finds a film.
     */
    public function findOneByTmdbId(int $tmdbId, string $type = 'movie'): ?Work
    {
        return $this->findOneByExternalId(ExternalId::tmdbFor($type), (string) $tmdbId);
    }

    /**
     * Works that have a TMDB id but no IMDb id yet — what the backfill walks.
     *
     * @return list<Work>
     */
    public function findMissingImdbId(int $limit = 500): array
    {
        return $this->createQueryBuilder('w')
            ->addSelect('x')
            // Either TMDB id space: series carry theirs under tmdb_tv.
            ->join('w.externalIds', 'x', 'WITH', "x.source IN ('tmdb', 'tmdb_tv')")
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere("NOT EXISTS (
                SELECT 1 FROM App\\Entity\\ExternalId imdb
                WHERE imdb.work = w AND imdb.source = 'imdb'
            )")
            ->orderBy('w.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByType(string $type): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.type = :type')
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The other titles in this one's collection, in story order.
     *
     * Returned as plain rows rather than entities: the strip draws a poster, a
     * part number and a link, and hydrating twenty Works to read four columns
     * would pull their genres and ratings along behind them.
     *
     * @return list<array{id: int, type: string, slug: string, title: string, poster: ?string, part: ?int}>
     */
    public function collectionSiblings(Work $work, int $limit = 30): array
    {
        $name = $work->getExtra()['collection']['name'] ?? null;
        if (!\is_string($name) || '' === $name) {
            return [];
        }

        /** @var list<array{id: int, type: string, slug: string, title: string, poster: ?string, part: ?int}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT id, type, slug, title, poster,
                    (extra->'collection'->>'part')::int AS part
             FROM works
             WHERE deleted_at IS NULL
               AND extra->'collection'->>'name' = :name
             ORDER BY part NULLS LAST, year NULLS LAST, id
             LIMIT :limit",
            ['name' => $name, 'limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return $rows;
    }

    /**
     * Announced but not out. Ordered by the date itself — ordering by year put
     * everything releasing in the same year in arbitrary order.
     *
     * Selected as ids and then loaded, rather than as one query fetch-joining
     * genres and ratings. Both of those are to-many, so the join multiplied
     * each work by its rows and LIMIT counted the product: asking for twenty
     * returned thirteen, and asking for forty returned twenty-three. The
     * caller wants whole works, so the limit has to apply to works.
     *
     * @return list<Work>
     */
    public function findUpcoming(int $limit = 20): array
    {
        $ids = $this->createQueryBuilder('w')
            ->select('w.id')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.releaseDate > CURRENT_DATE()')
            ->orderBy('w.releaseDate', 'ASC')
            ->addOrderBy('w.popularity', 'DESC')
            ->addOrderBy('w.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $ids) {
            return [];
        }

        /*
         * No fetch-joins here. The caller preloads through WorkHydrator, which
         * loads genres and ratings anyway — joining them again would send the
         * same rows twice, multiplied by each other.
         */
        /** @var list<Work> $works */
        $works = $this->createQueryBuilder('w')
            ->andWhere('w.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('w.releaseDate', 'ASC')
            ->addOrderBy('w.popularity', 'DESC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $works;
    }

    /**
     * Out recently — the other half of the release queue.
     *
     * Ordered by popularity rather than by date, which is the whole difference
     * between this and "recently added". Sorting the catalog by added_at after
     * a bulk crawl returns whatever the crawler reached last, and that is a
     * wall of zero-popularity titles nobody has heard of; a visitor reads
     * "latest" as "what is out now", not as a log of our crawl.
     *
     * A poster is required for the same reason: this is a row of posters.
     *
     * @return list<Work>
     */
    public function findRecentlyReleased(int $limit = 20, int $days = 90): array
    {
        $ids = $this->createQueryBuilder('w')
            ->select('w.id')
            ->andWhere('w.deletedAt IS NULL')
            ->andWhere('w.poster IS NOT NULL')
            ->andWhere('w.releaseDate <= CURRENT_DATE()')
            ->andWhere('w.releaseDate >= :from')
            ->setParameter('from', (new \DateTimeImmutable())->modify(sprintf('-%d days', max(1, $days))))
            ->orderBy('w.popularity', 'DESC')
            ->addOrderBy('w.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $ids) {
            return [];
        }

        /** @var list<Work> $works */
        $works = $this->createQueryBuilder('w')
            ->andWhere('w.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('w.popularity', 'DESC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $works;
    }

    /**
     * Values the certification filter can offer, most used first.
     *
     * @return list<string>
     */
    public function certifications(): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT certification FROM works
             WHERE deleted_at IS NULL AND COALESCE(certification, '') <> ''
             GROUP BY certification ORDER BY COUNT(*) DESC LIMIT 20",
        )->fetchFirstColumn();

        return array_map('strval', $rows);
    }

    /**
     * The span the year filter should offer.
     *
     * @return array{min: int|null, max: int|null}
     */
    public function yearBounds(): array
    {
        $row = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT MIN(year) AS min, MAX(year) AS max FROM works WHERE deleted_at IS NULL AND year IS NOT NULL',
        )->fetchAssociative();

        return [
            'min' => isset($row['min']) ? (int) $row['min'] : null,
            'max' => isset($row['max']) ? (int) $row['max'] : null,
        ];
    }

    /**
     * The decades a browse filter can usefully offer, newest first.
     *
     * Not derived from yearBounds(): the catalogue reports 1966 to 2026, but
     * the sixties and seventies hold one title each and the eighties none at
     * all, so a list built from the span offers three decades that return
     * nothing or nearly nothing.
     *
     * One indexed probe per decade rather than a GROUP BY over every row —
     * 5ms against 268ms, and the answer is the same four decades. The OFFSET
     * is what makes it "at least fifty", and it stops reading there.
     *
     * @return list<int>
     */
    public function decades(int $atLeast = 50): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT d FROM generate_series(1900, 2100, 10) AS d
             WHERE EXISTS (
                 SELECT 1 FROM works w
                 WHERE w.deleted_at IS NULL AND w.year >= d AND w.year < d + 10
                 OFFSET :atLeast LIMIT 1
             )
             ORDER BY d DESC',
            ['atLeast' => max(0, $atLeast - 1)],
        )->fetchFirstColumn();

        return array_map(intval(...), $rows);
    }

    /**
     * @return list<array{code: string, count: int}>
     */
    public function languages(): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT original_language AS code, COUNT(*) AS n FROM works
             WHERE deleted_at IS NULL AND COALESCE(original_language, '') <> ''
             GROUP BY original_language ORDER BY n DESC LIMIT 20",
        )->fetchAllAssociative();

        return array_map(static fn (array $row) => [
            'code' => (string) $row['code'],
            'count' => (int) $row['n'],
        ], $rows);
    }

    /* -------------------------------------------------------------- admin */

    /**
     * One page of the admin's works table.
     *
     * Built in SQL rather than DQL, for the same reason WorkSearch is: the
     * useful search here is `title ILIKE '%…%'`, which the gin_trgm index on
     * works.title can answer, and DQL has no ILIKE. Written as LOWER(title)
     * LIKE instead it would be a sequential scan of 412k rows on every
     * keystroke.
     *
     * Ids come back first and the entities are loaded from them, so paging
     * happens on single rows and no fetch-join can multiply them.
     *
     * @param array{q?: string|null, type?: string|null, status?: string|null, genre?: string|null, missing?: string|null, yearFrom?: int|null, yearTo?: int|null, sort?: string|null} $filters
     *
     * @return array{items: list<Work>, total: int}
     */
    public function adminPage(array $filters, int $offset, int $limit): array
    {
        [$where, $params] = $this->adminConditions($filters);
        $connection = $this->getEntityManager()->getConnection();

        $total = (int) $connection->executeQuery(
            'SELECT COUNT(*) FROM works w WHERE '.$where,
            $params,
        )->fetchOne();

        if (0 === $total) {
            return ['items' => [], 'total' => 0];
        }

        $ids = $connection->executeQuery(
            'SELECT w.id FROM works w WHERE '.$where.'
             ORDER BY '.$this->adminOrder($filters['sort'] ?? null).'
             LIMIT :limit OFFSET :offset',
            [...$params, 'limit' => $limit, 'offset' => $offset],
        )->fetchFirstColumn();

        $ids = array_map(intval(...), $ids);
        if ([] === $ids) {
            return ['items' => [], 'total' => $total];
        }

        /** @var list<Work> $works */
        $works = $this->createQueryBuilder('w')
            ->andWhere('w.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // Back into the order the page was selected in.
        $byId = [];
        foreach ($works as $work) {
            $byId[(int) $work->getId()] = $work;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return ['items' => $ordered, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function adminConditions(array $filters): array
    {
        $clauses = [];
        $params = [];

        // The admin is the only reader allowed to ask for hidden rows, so this
        // is the only place the default can be overridden.
        $clauses[] = match ($filters['status'] ?? null) {
            'deleted' => 'w.deleted_at IS NOT NULL',
            'all' => 'TRUE',
            default => 'w.deleted_at IS NULL',
        };

        $term = trim((string) ($filters['q'] ?? ''));
        if ('' !== $term) {
            // ILIKE, not LOWER(...) LIKE: only the former can use the trigram
            // index, and this table has 412k rows.
            $clauses[] = '(w.title ILIKE :q OR w.original_title ILIKE :q OR w.slug ILIKE :q)';
            $params['q'] = '%'.$term.'%';
        }

        if (null !== ($filters['type'] ?? null)) {
            $clauses[] = 'w.type = :type';
            $params['type'] = $filters['type'];
        }

        if (null !== ($filters['genre'] ?? null)) {
            $clauses[] = 'EXISTS (SELECT 1 FROM work_genre wg JOIN genres g ON g.id = wg.genre_id
                                  WHERE wg.work_id = w.id AND g.slug = :genre)';
            $params['genre'] = $filters['genre'];
        }

        if (null !== ($filters['yearFrom'] ?? null)) {
            $clauses[] = 'w.year >= :yearFrom';
            $params['yearFrom'] = $filters['yearFrom'];
        }

        if (null !== ($filters['yearTo'] ?? null)) {
            $clauses[] = 'w.year <= :yearTo';
            $params['yearTo'] = $filters['yearTo'];
        }

        // "What needs fixing" — the reason to open this table at all.
        $missing = $filters['missing'] ?? null;
        if (null !== $missing) {
            $clauses[] = match ($missing) {
                'poster' => 'w.poster IS NULL',
                'overview' => "COALESCE(w.overview, '') = ''",
                'year' => 'w.year IS NULL',
                'genre' => 'NOT EXISTS (SELECT 1 FROM work_genre wg WHERE wg.work_id = w.id)',
                'imdb' => "NOT EXISTS (SELECT 1 FROM external_ids x WHERE x.work_id = w.id AND x.source = 'imdb')",
                default => 'TRUE',
            };
        }

        return [implode(' AND ', $clauses), $params];
    }

    /** Sorting the admin table. Anything unrecognised falls back to popularity. */
    private function adminOrder(?string $sort): string
    {
        // A stable tiebreak on every branch, so page 2 cannot repeat page 1.
        return match ($sort) {
            'title' => 'w.title ASC, w.id DESC',
            'year' => 'w.year DESC NULLS LAST, w.id DESC',
            'oldest' => 'w.year ASC NULLS LAST, w.id DESC',
            'added' => 'w.added_at DESC, w.id DESC',
            'score' => 'w.external_score DESC NULLS LAST, w.id DESC',
            'hidden' => 'w.deleted_at DESC NULLS LAST, w.id DESC',
            default => 'w.popularity DESC NULLS LAST, w.id DESC',
        };
    }
}
