<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\AccessTokenRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Validates the bearer token against the database and resolves it to a user.
 */
class AccessTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly AccessTokenRepository $accessTokenRepository,
    ) {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        $token = $this->accessTokenRepository->findOneByToken($accessToken);

        if (null === $token || $token->isExpired()) {
            throw new BadCredentialsException('Invalid or expired access token.');
        }

        $user = $token->getUser();
        if (!$user instanceof User || $user->isDeleted()) {
            throw new BadCredentialsException('Invalid or expired access token.');
        }

        return new UserBadge($user->getUserIdentifier());
    }
}
