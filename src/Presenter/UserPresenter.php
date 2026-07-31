<?php

namespace App\Presenter;

use App\Entity\User;

final class UserPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function one(User $user): array
    {
        return [
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'name' => $user->getName(),
            'tagline' => $user->getTagline(),
            'bio' => $user->getBio(),
            'location' => $user->getLocation(),
            'avatar' => $user->getAvatar(),
            'joinedAt' => $user->getCreatedAt()?->format('Y-m-d'),
            /*
             * Only meaningful about yourself. They ride along on the public
             * profile too, which is how the shape stays one shape — the address
             * itself never does, and that is the part that matters.
             */
            'hasPassword' => $user->hasPassword(),
            'handlePending' => $user->isHandlePending(),
        ];
    }

    /**
     * The signed-in person's own record, with the things only they may see.
     *
     * @return array<string, mixed>
     */
    public static function self(User $user): array
    {
        return [
            ...self::one($user),
            'email' => $user->getEmail(),
            'emailVerified' => $user->isEmailVerified(),
            /*
             * What was granted, not what the firewall expands it to: the front
             * end only needs to know whether to offer the admin link, and
             * shipping the expansion would imply the browser can be trusted
             * with the decision. Every /api/admin route checks again.
             */
            'roles' => $user->getGrantedRoles(),
        ];
    }

    /**
     * An account as the admin table sees it: the private columns, the state,
     * and — when the caller has counted them — what the account has done.
     *
     * @param array{entries?: int, reviews?: int, followers?: int, following?: int} $stats
     *
     * @return array<string, mixed>
     */
    public static function admin(User $user, array $stats = []): array
    {
        return [
            ...self::self($user),
            'createdAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $user->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'deletedAt' => $user->getDeletedAt()?->format(\DateTimeInterface::ATOM),
            'stats' => [
                'entries' => $stats['entries'] ?? 0,
                'reviews' => $stats['reviews'] ?? 0,
                'followers' => $stats['followers'] ?? 0,
                'following' => $stats['following'] ?? 0,
            ],
        ];
    }

    /**
     * Compact identity used in feeds and review lists.
     *
     * @return array<string, mixed>
     */
    public static function compact(User $user): array
    {
        return [
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'name' => $user->getName(),
            'avatar' => $user->getAvatar(),
        ];
    }
}
