<?php

namespace App\Repository;

use App\Entity\DeviceToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeviceToken>
 */
class DeviceTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceToken::class);
    }

    public function findOneByToken(string $token): ?DeviceToken
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * @return list<DeviceToken>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.lastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every device belonging to any of these users, in one query.
     *
     * The digest sends to a few hundred people at once and would otherwise do a
     * SELECT per person. Returned keyed by user id because the caller is
     * building one message per user, not one per device.
     *
     * @param list<int> $userIds
     *
     * @return array<int, list<DeviceToken>>
     */
    public function findForUserIds(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var list<DeviceToken> $rows */
        $rows = $this->createQueryBuilder('d')
            ->andWhere('d.user IN (:ids)')
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->getResult();

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[$row->getUser()->getId()][] = $row;
        }

        return $byUser;
    }

    /**
     * Forget a token FCM has told us is dead.
     *
     * Deliberately keyed by the token string rather than the entity: the sender
     * learns about a dead token from a response body, long after the entity it
     * came from may have been detached, and re-fetching it only to delete it is
     * a round trip to discover something we already know.
     */
    public function deleteByToken(string $token): void
    {
        $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.token = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->execute();
    }

    /**
     * Drop installs that have not checked in for a long time.
     *
     * The app reports its token on every cold start, so silence this long means
     * the app is gone. FCM would keep accepting sends to it regardless, which
     * is how a push list quietly fills up with phones that were traded in years
     * ago.
     */
    public function deleteStale(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.lastSeenAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
