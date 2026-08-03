<?php

namespace App\Repository;

use App\Entity\Person;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Person>
 */
class PersonRepository extends ServiceEntityRepository
{
    /**
     * People created during this flush cycle. findOneBy() only sees committed
     * rows, so without this a crawl batch that credits the same person on two
     * films tries to insert them twice and trips the unique slug.
     *
     * @var array<string, Person>
     */
    private array $pending = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Person::class);
    }

    /**
     * Loads the people behind a list of names in one statement.
     *
     * findOrCreate() answers from $pending whenever it can, so filling it first
     * turns a lookup per cast member into a single query. A film credits about
     * fifteen people; asking for them one at a time is fifteen round trips to
     * the database, and during a long crawl that is what sets the pace — not
     * the API, not the CPU.
     *
     * Safe to hold managed entities here because every clear() of the entity
     * manager calls resetPending(), so nothing detached is ever handed back.
     *
     * @param list<string> $names
     */
    public function warm(array $names): void
    {
        $slugs = [];
        foreach ($names as $name) {
            $slug = $this->slugFor($name);
            if ('' !== $slug && !isset($this->pending[$slug])) {
                // Keyed, so a name credited twice is asked for once.
                $slugs[$slug] = true;
            }
        }

        if ([] === $slugs) {
            return;
        }

        foreach ($this->findBy(['slug' => array_keys($slugs)]) as $person) {
            $this->pending[(string) $person->getSlug()] = $person;
        }
    }

    public function findOrCreate(string $name, ?string $photo = null, ?string $externalId = null): Person
    {
        $slug = $this->slugFor($name);
        $person = $this->pending[$slug] ?? $this->findOneBy(['slug' => $slug]);

        if (null === $person) {
            $person = new Person($slug, mb_substr(trim($name), 0, 180));
            $this->getEntityManager()->persist($person);
            $this->pending[$slug] = $person;
        }

        // Fill in anything we did not know last time we saw them.
        if (null !== $photo && '' !== $photo && null === $person->getPhoto()) {
            $person->setPhoto(mb_substr($photo, 0, 500));
        }
        if (null !== $externalId && null === $person->getExternalId()) {
            $person->setExternalId($externalId);
        }

        return $person;
    }

    /**
     * Name search for the "by person" filter.
     *
     * @return list<Person>
     */
    public function searchByName(string $query, int $limit = 8): array
    {
        // ILIKE is Postgres, not DQL — lower() both sides instead.
        return $this->createQueryBuilder('p')
            ->andWhere('LOWER(p.name) LIKE :like')
            ->setParameter('like', '%'.mb_strtolower(trim($query)).'%')
            ->orderBy('p.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findBySlug(string $slug): ?Person
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Everything a person is credited on, most popular first.
     *
     * One query rather than one per role. A prolific actor is on hundreds of
     * titles, and asking separately for cast, then director, then writer would
     * scan the same rows three times — the role comes back with the work id and
     * the caller groups them.
     *
     * Ids only. Hydrating is WorkHydrator's job, and it is the only way to draw
     * a wall of posters without a query per card.
     *
     * `idx_credit_person` is (person_id, role), so the lookup is an index scan;
     * the sort is over one person's credits, which is hundreds of rows at worst.
     *
     * @return list<array{workId: int, role: string, character: ?string}>
     */
    public function creditsFor(int $personId, int $limit = 200): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT c.work_id, c.role, NULLIF(c.character_name, '') AS character_name
             FROM credits c
             JOIN works w ON w.id = c.work_id
             WHERE c.person_id = :person AND w.deleted_at IS NULL
             ORDER BY w.popularity DESC NULLS LAST, w.id DESC
             LIMIT :limit",
            ['person' => $personId, 'limit' => $limit],
            ['person' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();

        return array_map(static fn (array $row) => [
            'workId' => (int) $row['work_id'],
            'role' => (string) $row['role'],
            'character' => $row['character_name'] ?? null,
        ], $rows);
    }

    /* -------------------------------------------------------------- admin */

    /**
     * One page of people for the admin table.
     *
     * SQL rather than DQL for the same reason the works table is: the search
     * that matters is `name ILIKE '%…%'` over 1.1 million rows, and DQL has no
     * ILIKE. The credit count comes back with the row, because a person is
     * mostly interesting for how many things they are on — and asking each of
     * them separately would be a query per row.
     *
     * @param array{q?: string|null, photo?: string|null, credits?: string|null, sort?: string|null} $filters
     *
     * @return array{items: list<array{person: Person, credits: int}>, total: int}
     */
    public function adminPage(array $filters, int $offset, int $limit): array
    {
        [$where, $params] = $this->adminConditions($filters);
        $connection = $this->getEntityManager()->getConnection();

        $total = (int) $connection->executeQuery(
            'SELECT COUNT(*) FROM people p WHERE '.$where,
            $params,
        )->fetchOne();

        if (0 === $total) {
            return ['items' => [], 'total' => 0];
        }

        $rows = $connection->executeQuery(
            'SELECT p.id, (SELECT COUNT(*) FROM credits c WHERE c.person_id = p.id) AS n
             FROM people p WHERE '.$where.'
             ORDER BY '.$this->adminOrder($filters['sort'] ?? null).'
             LIMIT :limit OFFSET :offset',
            [...$params, 'limit' => $limit, 'offset' => $offset],
        )->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        if ([] === $counts) {
            return ['items' => [], 'total' => $total];
        }

        /** @var list<Person> $people */
        $people = $this->createQueryBuilder('p')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('ids', array_keys($counts))
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($people as $person) {
            $byId[(int) $person->getId()] = $person;
        }

        // Back into the order the page was selected in.
        $items = [];
        foreach ($counts as $id => $n) {
            if (isset($byId[$id])) {
                $items[] = ['person' => $byId[$id], 'credits' => $n];
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function adminConditions(array $filters): array
    {
        $clauses = ['TRUE'];
        $params = [];

        $term = trim((string) ($filters['q'] ?? ''));
        if ('' !== $term) {
            $clauses[] = '(p.name ILIKE :q OR p.slug ILIKE :q)';
            $params['q'] = '%'.$term.'%';
        }

        $photo = $filters['photo'] ?? null;
        if (null !== $photo) {
            $clauses[] = 'yes' === $photo ? 'p.photo IS NOT NULL' : 'p.photo IS NULL';
        }

        // Orphans are the interesting case: the crawler creates a person the
        // moment it sees a name, so a person with no credits left is a row
        // nothing points at any more.
        $credits = $filters['credits'] ?? null;
        if (null !== $credits) {
            $exists = 'EXISTS (SELECT 1 FROM credits c WHERE c.person_id = p.id)';
            $clauses[] = 'none' === $credits ? 'NOT '.$exists : $exists;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Anything unrecognised falls back to alphabetical, and that default is
     * deliberate.
     *
     * Ordering by the credit count means computing it for all 1.1 million
     * people before the LIMIT can throw 1,122,139 of them away — measured at
     * 1.2 seconds, against 170ms for the name. Sorting by name lets Postgres
     * walk an index, take twenty-five rows, and count only those. "Most
     * credited" is still offered, because it is the right question sometimes;
     * it is just not worth paying for on every visit to the page.
     */
    private function adminOrder(?string $sort): string
    {
        return match ($sort) {
            'credits' => 'n DESC, p.id DESC',
            'fewest' => 'n ASC, p.id DESC',
            'newest' => 'p.id DESC',
            default => 'p.name ASC, p.id DESC',
        };
    }

    /**
     * How many credits each of these people has, batched.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    public function creditCountsFor(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT person_id AS id, COUNT(*) AS n FROM credits WHERE person_id IN (?) GROUP BY person_id',
            [$ids],
            [ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $counts = array_fill_keys($ids, 0);
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The key a person is stored under. Shared by warm() and findOrCreate() so
     * the two cannot drift — a mismatch would silently reintroduce the query
     * per person that warming exists to remove.
     *
     * people.slug is varchar(200), people.name varchar(180).
     */
    private function slugFor(string $name): string
    {
        return mb_substr(self::slugify($name), 0, 200);
    }

    /** Called when the entity manager is cleared: the pending objects are gone. */
    public function resetPending(): void
    {
        $this->pending = [];
    }

    /**
     * Unicode-preserving slug: lowercase, and every run of non-alphanumerics
     * becomes a dash. Transliterating to ASCII first looked tidier but mangled
     * real names — iconv turns "Clément" into "cl-ement" and drops CJK names
     * to nothing at all, so every Japanese credit would have collapsed into one
     * person. Postgres [[:alnum:]] folds the same way, which keeps the crawler
     * and the migrations agreeing.
     */
    public static function slugify(string $name): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower(trim($name))) ?? '';

        return trim($slug, '-') ?: 'unknown';
    }
}
