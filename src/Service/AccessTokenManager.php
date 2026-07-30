<?php

namespace App\Service;

use App\Entity\AccessToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Issues and rotates self-managed access tokens stored in the database.
 */
class AccessTokenManager
{
    private const ACCESS_TOKEN_TTL = '+1 hour';
    private const REFRESH_TOKEN_TTL = '+30 days';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function create(User $user, ?Request $request = null): AccessToken
    {
        $now = new \DateTimeImmutable();

        $accessToken = (new AccessToken())
            ->setUser($user)
            ->setToken($this->generateToken())
            ->setRefreshToken($this->generateToken())
            ->setExpiresAt($now->modify(self::ACCESS_TOKEN_TTL))
            ->setRefreshTokenExpiresAt($now->modify(self::REFRESH_TOKEN_TTL));

        if (null !== $request) {
            $accessToken
                ->setIpAddress($request->getClientIp())
                ->setUserAgent(substr((string) $request->headers->get('User-Agent'), 0, 255));
        }

        $this->entityManager->persist($accessToken);
        $this->entityManager->flush();

        return $accessToken;
    }

    /**
     * Rotate both tokens in-place on the same row. Keeps login metadata.
     */
    public function refresh(AccessToken $accessToken): AccessToken
    {
        $now = new \DateTimeImmutable();

        $accessToken
            ->setToken($this->generateToken())
            ->setRefreshToken($this->generateToken())
            ->setExpiresAt($now->modify(self::ACCESS_TOKEN_TTL))
            ->setRefreshTokenExpiresAt($now->modify(self::REFRESH_TOKEN_TTL));

        $this->entityManager->flush();

        return $accessToken;
    }

    public function revoke(AccessToken $accessToken): void
    {
        $accessToken->softDelete();
        $this->entityManager->flush();
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
