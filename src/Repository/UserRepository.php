<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneActiveByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username, 'deletedAt' => null]);
    }

    public function existsActiveByUsername(string $username): bool
    {
        return $this->count(['username' => $username, 'deletedAt' => null]) > 0;
    }

    /** Addresses are stored folded, so the lookup folds too. */
    public function findOneActiveByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email)), 'deletedAt' => null]);
    }

    public function existsActiveByEmail(string $email): bool
    {
        return $this->count(['email' => mb_strtolower(trim($email)), 'deletedAt' => null]) > 0;
    }

    /* -------------------------------------------------------------- admin */

    /**
     * One page of accounts for the admin table.
     *
     * @param array{q?: string|null, role?: string|null, status?: string|null, sort?: string|null} $filters
     *
     * @return array{items: list<User>, total: int}
     */
    public function page(array $filters, int $offset, int $limit): array
    {
        $items = $this->filtered($filters)
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->sort($items, $filters['sort'] ?? null);

        /** @var list<User> $rows */
        $rows = $items->getQuery()->getResult();

        $total = (int) $this->filtered($filters)
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * @param array{q?: string|null, role?: string|null, status?: string|null, sort?: string|null} $filters
     */
    private function filtered(array $filters): QueryBuilder
    {
        $builder = $this->createQueryBuilder('u');

        // Soft delete is not a Doctrine filter in this application — every
        // query decides for itself. The admin is the one place that wants to
        // see deleted rows, so it says which it means.
        match ($filters['status'] ?? null) {
            'deleted' => $builder->andWhere('u.deletedAt IS NOT NULL'),
            'all' => $builder,
            default => $builder->andWhere('u.deletedAt IS NULL'),
        };

        $term = trim((string) ($filters['q'] ?? ''));
        if ('' !== $term) {
            // ILIKE is Postgres, not DQL — lower() both sides instead.
            $builder
                ->andWhere('LOWER(u.username) LIKE :term OR LOWER(u.name) LIKE :term OR LOWER(u.email) LIKE :term')
                ->setParameter('term', '%'.mb_strtolower($term).'%');
        }

        $role = $filters['role'] ?? null;
        if (null !== $role) {
            /*
             * roles is a json column with no containment index, and DQL cannot
             * reach inside it at all. Role holders are a handful of rows, so
             * resolve them to ids first and let the main query use a plain IN.
             * An empty result must still match nothing, hence the -1.
             */
            $ids = $this->idsHolding($role);
            $builder->andWhere('u.id IN (:roleIds)')->setParameter('roleIds', [] !== $ids ? $ids : [-1]);
        }

        return $builder;
    }

    /** Sorting the account list. Anything unrecognised falls back to newest. */
    private function sort(QueryBuilder $builder, ?string $sort): void
    {
        match ($sort) {
            'oldest' => $builder->orderBy('u.createdAt', 'ASC'),
            'username' => $builder->orderBy('u.username', 'ASC'),
            'name' => $builder->orderBy('u.name', 'ASC'),
            default => $builder->orderBy('u.createdAt', 'DESC'),
        };

        // A stable tiebreak, so page 2 cannot repeat a row from page 1.
        $builder->addOrderBy('u.id', 'DESC');
    }

    /**
     * Ids of everybody holding a role, straight from the json column.
     *
     * @return list<int>
     */
    public function idsHolding(string $role): array
    {
        // CAST(...) rather than `:role::jsonb`: DBAL's parser reads the `::`
        // that follows a named placeholder as part of the placeholder.
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM users WHERE roles::jsonb @> CAST(:role AS jsonb)',
            ['role' => json_encode([$role])],
        );

        return array_map(intval(...), $rows);
    }

    /**
     * Everybody with any role at all, for `app:user:role --list`.
     *
     * @return list<User>
     */
    public function findWithAnyRole(): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT id FROM users WHERE roles::text <> '[]' AND deleted_at IS NULL ORDER BY id",
        );

        if ([] === $ids) {
            return [];
        }

        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->andWhere('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }

    /**
     * How many accounts there are, by state and by role, for the overview tiles.
     *
     * @return array{total: int, active: int, deleted: int, admins: int, moderators: int}
     */
    public function counts(): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE deleted_at IS NULL) AS active,
                    COUNT(*) FILTER (WHERE deleted_at IS NOT NULL) AS deleted,
                    COUNT(*) FILTER (WHERE roles::jsonb @> \'["ROLE_ADMIN"]\'::jsonb) AS admins,
                    COUNT(*) FILTER (WHERE roles::jsonb @> \'["ROLE_MODERATOR"]\'::jsonb) AS moderators
             FROM users',
        ) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'deleted' => (int) ($row['deleted'] ?? 0),
            'admins' => (int) ($row['admins'] ?? 0),
            'moderators' => (int) ($row['moderators'] ?? 0),
        ];
    }

    /**
     * Shelf, review and follow counts for a page of accounts, in four queries
     * rather than four per row.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{entries: int, reviews: int, followers: int, following: int}>
     */
    public function statsFor(array $ids): array
    {
        $blank = ['entries' => 0, 'reviews' => 0, 'followers' => 0, 'following' => 0];
        if ([] === $ids) {
            return [];
        }

        $stats = array_fill_keys($ids, $blank);
        $connection = $this->getEntityManager()->getConnection();

        $sources = [
            'entries' => 'SELECT user_id AS id, COUNT(*) AS n FROM entries WHERE user_id IN (?) GROUP BY user_id',
            'reviews' => 'SELECT user_id AS id, COUNT(*) AS n FROM reviews WHERE user_id IN (?) GROUP BY user_id',
            'followers' => 'SELECT followed_id AS id, COUNT(*) AS n FROM follows WHERE followed_id IN (?) GROUP BY followed_id',
            'following' => 'SELECT follower_id AS id, COUNT(*) AS n FROM follows WHERE follower_id IN (?) GROUP BY follower_id',
        ];

        foreach ($sources as $key => $sql) {
            $rows = $connection->executeQuery($sql, [$ids], [ArrayParameterType::INTEGER])->fetchAllAssociative();

            foreach ($rows as $row) {
                $stats[(int) $row['id']][$key] = (int) $row['n'];
            }
        }

        return $stats;
    }

    /**
     * The most recent sign-ups, for the overview.
     *
     * @return list<User>
     */
    public function findRecent(int $limit = 8): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->andWhere('u.deletedAt IS NULL')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $users;
    }

    /**
     * Accounts in these timezones that have not had a digest recently.
     *
     * Narrowed to people with at least one registered device, because the
     * digest's expensive half is the catalog query that follows and running it
     * for accounts that have never installed the app is work with nowhere to
     * go. Somebody who signs in on a phone at 08:59 is picked up by the next
     * run, which is the correct answer anyway.
     *
     * @param list<string> $timezones
     *
     * @return list<User>
     */
    public function findForDigest(array $timezones, \DateTimeImmutable $notSince): array
    {
        if ([] === $timezones) {
            return [];
        }

        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->andWhere('u.deletedAt IS NULL')
            ->andWhere('u.timezone IN (:zones)')
            ->andWhere('u.pushDigestAt IS NULL OR u.pushDigestAt < :notSince')
            ->andWhere('EXISTS (
                SELECT 1 FROM App\Entity\DeviceToken d WHERE d.user = u
            )')
            ->setParameter('zones', $timezones)
            ->setParameter('notSince', $notSince)
            ->getQuery()
            ->getResult();

        return $users;
    }
}
