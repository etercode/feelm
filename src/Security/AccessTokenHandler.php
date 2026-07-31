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

        /*
         * Handed back with the user, not just their name.
         *
         * A UserBadge carrying only an identifier makes Symfony ask the user
         * provider for it, which loads the same row again by username — so
         * every authenticated request read the users table twice, once by id
         * to get here and once by name to answer that. The loader short-
         * circuits it. UserChecker still runs either way, so a deleted account
         * is still refused.
         */
        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
