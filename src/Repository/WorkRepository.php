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

    public function findOneByTypeAndSlug(string $type, string $slug): ?Work
    {
        return $this->createQueryBuilder('w')
            ->addSelect('g', 'r', 'x')
            ->leftJoin('w.genres', 'g')
            ->leftJoin('w.ratings', 'r')
            ->leftJoin('w.externalIds', 'x')
            ->andWhere('w.type = :type')
            ->andWhere('w.slug = :slug')
            ->setParameter('type', $type)
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The only correct way to find a crawled row again. */
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

    public function findOneByTmdbId(int $tmdbId): ?Work
    {
        return $this->findOneByExternalId(ExternalId::SOURCE_TMDB, (string) $tmdbId);
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
            ->join('w.externalIds', 'x', 'WITH', "x.source = 'tmdb'")
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
            ->andWhere('w.type = :type')
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Announced but not out. Ordered by the date itself — ordering by year put
     * everything releasing in the same year in arbitrary order.
     *
     * @return list<Work>
     */
    public function findUpcoming(int $limit = 20): array
    {
        return $this->createQueryBuilder('w')
            ->addSelect('g', 'r')
            ->leftJoin('w.genres', 'g')
            ->leftJoin('w.ratings', 'r')
            ->andWhere('w.releaseDate > CURRENT_DATE()')
            ->orderBy('w.releaseDate', 'ASC')
            ->addOrderBy('w.popularity', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
             WHERE COALESCE(certification, '') <> ''
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
            'SELECT MIN(year) AS min, MAX(year) AS max FROM works WHERE year IS NOT NULL',
        )->fetchAssociative();

        return [
            'min' => isset($row['min']) ? (int) $row['min'] : null,
            'max' => isset($row['max']) ? (int) $row['max'] : null,
        ];
    }

    /**
     * @return list<array{code: string, count: int}>
     */
    public function languages(): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT original_language AS code, COUNT(*) AS n FROM works
             WHERE COALESCE(original_language, '') <> ''
             GROUP BY original_language ORDER BY n DESC LIMIT 20",
        )->fetchAllAssociative();

        return array_map(static fn (array $row) => [
            'code' => (string) $row['code'],
            'count' => (int) $row['n'],
        ], $rows);
    }
}
