<?php

namespace App\Repository;

use App\Entity\AccessToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessToken>
 */
class AccessTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessToken::class);
    }

    public function findOneByToken(string $token): ?AccessToken
    {
        return $this->findOneBy(['token' => $token, 'deletedAt' => null]);
    }

    public function findOneByRefreshToken(string $refreshToken): ?AccessToken
    {
        return $this->findOneBy(['refreshToken' => $refreshToken, 'deletedAt' => null]);
    }

    /**
     * Signs an account out of everything, by soft-deleting its live tokens.
     *
     * Deleting an account is meant to take effect now, not in up to an hour
     * when the access token expires. Done in the database rather than by
     * loading entities because a long-lived account can have a lot of rows.
     *
     * @return int how many were revoked
     */
    public function revokeAllFor(User $user): int
    {
        return (int) $this->createQueryBuilder('t')
            ->update()
            ->set('t.deletedAt', ':now')
            ->andWhere('t.user = :user')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
