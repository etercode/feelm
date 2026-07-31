<?php

namespace App\Repository;

use App\Entity\Credit;
use App\Entity\Person;
use App\Entity\Work;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Credits, for the admin. The crawler writes them through
 * CatalogWorkPersister, which replaces a work's credits wholesale and never
 * needs to read one back.
 *
 * @extends ServiceEntityRepository<Credit>
 */
class CreditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Credit::class);
    }

    /**
     * Everybody credited on one work, cast first and in billing order.
     *
     * The person is joined in: the presenter names them, and a cast of thirty
     * would otherwise be thirty queries.
     *
     * @return list<Credit>
     */
    public function forWork(Work $work): array
    {
        /** @var list<Credit> $credits */
        $credits = $this->createQueryBuilder('c')
            ->innerJoin('c.person', 'p')->addSelect('p')
            ->andWhere('c.work = :work')
            ->setParameter('work', $work)
            // Cast before crew, then billing order, then something stable.
            ->addSelect("CASE WHEN c.role = 'cast' THEN 0 ELSE 1 END AS HIDDEN crewLast")
            ->orderBy('crewLast', 'ASC')
            ->addOrderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $credits;
    }

    /**
     * Everything one person is credited on, newest work first.
     *
     * @return array{items: list<Credit>, total: int}
     */
    public function forPerson(Person $person, int $offset = 0, int $limit = 50): array
    {
        /** @var list<Credit> $credits */
        $credits = $this->createQueryBuilder('c')
            ->innerJoin('c.work', 'w')->addSelect('w')
            ->andWhere('c.person = :person')
            ->setParameter('person', $person)
            ->orderBy('w.year', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $total = (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.person = :person')
            ->setParameter('person', $person)
            ->getQuery()
            ->getSingleScalarResult();

        return ['items' => $credits, 'total' => $total];
    }

    /** The next free billing position on a work, so a new credit lands last. */
    public function nextPosition(Work $work, string $role): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->andWhere('c.work = :work')
            ->andWhere('c.role = :role')
            ->setParameter('work', $work)
            ->setParameter('role', $role)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
