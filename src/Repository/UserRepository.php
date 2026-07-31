<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneActiveByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username, 'deletedAt' => null]);
    }

    public function existsActiveByUsername(string $username): bool
    {
        return $this->count(['username' => $username, 'deletedAt' => null]) > 0;
    }

    /** Addresses are stored folded, so the lookup folds too. */
    public function findOneActiveByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email)), 'deletedAt' => null]);
    }

    public function existsActiveByEmail(string $email): bool
    {
        return $this->count(['email' => mb_strtolower(trim($email)), 'deletedAt' => null]) > 0;
    }
}
