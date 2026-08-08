<?php

namespace App\Repository;

use App\Entity\Follow;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Follow>
 */
class FollowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Follow::class);
    }

    public function findOnePair(User $follower, User $followed): ?Follow
    {
        return $this->findOneBy(['follower' => $follower, 'followed' => $followed]);
    }

    public function isFollowing(User $follower, User $followed): bool
    {
        return null !== $this->findOnePair($follower, $followed);
    }

    /**
     * @return list<Follow>
     */
    public function findFollowing(User $follower): array
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.followed', 'u')->addSelect('u')
            ->andWhere('f.follower = :follower')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('follower', $follower)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Follow>
     */
    public function findFollowers(User $followed): array
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.follower', 'u')->addSelect('u')
            ->andWhere('f.followed = :followed')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('followed', $followed)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<int>
     */
    /**
     * Which of *these* people the viewer already follows.
     *
     * followedIdsOf() answers for the whole list, and a search result page has
     * eight rows on it. Bounded by what is on screen rather than by how many
     * people somebody follows.
     *
     * @param list<int> $userIds
     *
     * @return list<int>
     */
    public function followedIdsAmong(User $follower, array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        return array_map('intval', $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT followed_id FROM follows WHERE follower_id = :me AND followed_id IN (:ids)',
            ['me' => $follower->getId(), 'ids' => $userIds],
            ['ids' => ArrayParameterType::INTEGER],
        )->fetchFirstColumn());
    }

    public function followedIdsOf(User $follower): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.followed) AS id')
            ->andWhere('f.follower = :follower')
            ->setParameter('follower', $follower)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r) => (int) $r['id'], $rows);
    }
}
