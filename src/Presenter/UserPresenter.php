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
        return [...self::one($user), 'email' => $user->getEmail(), 'emailVerified' => $user->isEmailVerified()];
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
