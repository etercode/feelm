<?php

namespace App\Repository;

use App\Entity\Person;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function findOrCreate(string $name, ?string $photo = null, ?string $externalId = null): Person
    {
        // people.slug is varchar(200), people.name varchar(180).
        $slug = mb_substr(self::slugify($name), 0, 200);
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
