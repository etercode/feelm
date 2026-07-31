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

    /**
     * The bearer token, with its owner already loaded.
     *
     * The user is joined in rather than left to the proxy. Every authenticated
     * request asks this question, and the very next thing the handler does is
     * read the user to check whether the account is deleted — which would
     * initialise the proxy and cost a second round trip, on every request.
     */
    public function findOneByToken(string $token): ?AccessToken
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.user', 'u')->addSelect('u')
            ->andWhere('t.token = :token')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
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
