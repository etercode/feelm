<?php

namespace App\Repository;

use App\Entity\SeenMark;
use App\Entity\User;
use App\Entity\Work;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * Which of *these* works the viewer has already seen.
     *
     * seenWorkIdsFor() answers the same question for their whole history, and
     * that is the wrong shape for the only thing the answer is used for. The
     * NEW badge is decided per card on screen, so the question is only ever
     * about the thirty or so titles in front of somebody — bounded by the page,
     * not by how long they have been a member.
     *
     * @param list<int> $workIds
     *
     * @return list<int>
     */
    public function seenAmong(User $user, array $workIds): array
    {
        if ([] === $workIds) {
            return [];
        }

        return array_map('intval', $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT work_id FROM seen_marks WHERE user_id = :user AND work_id IN (:ids)',
            ['user' => $user->getId(), 'ids' => $workIds],
            ['ids' => ArrayParameterType::INTEGER],
        )->fetchFirstColumn());
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
