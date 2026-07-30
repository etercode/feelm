<?php

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Work;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
    public function findActivity(?array $userIds, int $limit = 40): array
    {
        $qb = $this->createQueryBuilder('e')
            ->innerJoin('e.user', 'u')->addSelect('u')
            ->innerJoin('e.work', 'w')->addSelect('w')
            ->andWhere('u.deletedAt IS NULL')
            ->orderBy('e.updatedAt', 'DESC')
            ->setMaxResults($limit);

        if (null !== $userIds) {
            if ([] === $userIds) {
                return [];
            }
            $qb->andWhere('u.id IN (:ids)')->setParameter('ids', $userIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array{logged: int, finished: int, averageRating: float|null, byType: array<string, int>}
     */
    public function statsForUser(User $user): array
    {
        $entries = $this->findByUser($user);
        $byType = ['movie' => 0, 'series' => 0, 'game' => 0, 'book' => 0];
        $finished = 0;
        $ratings = [];

        foreach ($entries as $entry) {
            $type = $entry->getWork()?->getType();
            if (null !== $type && isset($byType[$type])) {
                ++$byType[$type];
            }
            if ('done' === $entry->getStatus()) {
                ++$finished;
            }
            if (null !== $entry->getRating()) {
                $ratings[] = $entry->getRating();
            }
        }

        return [
            'logged' => \count($entries),
            'finished' => $finished,
            'averageRating' => [] === $ratings ? null : round(array_sum($ratings) / \count($ratings), 1),
            'byType' => $byType,
        ];
    }
}
