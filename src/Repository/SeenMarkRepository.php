<?php

namespace App\Repository;

use App\Entity\SeenMark;
use App\Entity\User;
use App\Entity\Work;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Titles a person has opened since they last caught up. The wholesale
 * "everything is seen" case is a timestamp on the user, not rows in here.
 *
 * @extends ServiceEntityRepository<SeenMark>
 */
class SeenMarkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeenMark::class);
    }

    public function findOneByUserAndWork(User $user, Work $work): ?SeenMark
    {
        return $this->findOneBy(['user' => $user, 'work' => $work]);
    }

    /**
     * @return list<int>
     */
    public function seenWorkIdsFor(User $user): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.work) AS id')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => (int) $row['id'], $rows);
    }

    /** One statement, however many rows there are. */
    public function deleteAllFor(User $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
