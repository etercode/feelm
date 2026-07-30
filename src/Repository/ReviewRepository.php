<?php

namespace App\Repository;

use App\Entity\Work;
use App\Entity\Review;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    public function findOneByUserAndWork(User $user, Work $work): ?Review
    {
        return $this->findOneBy(['user' => $user, 'work' => $work]);
    }

    /**
     * @return list<Review>
     */
    public function findByWork(Work $work): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.user', 'u')->addSelect('u')
            ->andWhere('r.work = :work')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('work', $work)
            ->orderBy('r.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Review>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.work', 'w')->addSelect('w')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{average: float|null, count: int}
     */
    public function ratingOf(Work $work): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) AS average', 'COUNT(r.id) AS cnt')
            ->andWhere('r.work = :work')
            ->setParameter('work', $work)
            ->getQuery()
            ->getSingleResult();

        $count = (int) $row['cnt'];

        return [
            'average' => 0 === $count ? null : round((float) $row['average'], 1),
            'count' => $count,
        ];
    }

    public function countByUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }
}
