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
            'joinedAt' => $user->getCreatedAt()?->format('Y-m-d'),
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
        ];
    }
}
