<?php

namespace App\Presenter;

use App\Entity\Review;
use App\Entity\ReviewVersion;

final class ReviewPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function one(Review $review): array
    {
        return [
            'id' => $review->getId(),
            'userId' => $review->getUser()?->getId(),
            'itemId' => $review->getWork()?->getId(),
            'user' => $review->getUser() ? UserPresenter::compact($review->getUser()) : null,
            'rating' => $review->getRating(),
            'body' => $review->getBody(),
            'createdAt' => $review->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $review->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'history' => array_map(
                static fn (ReviewVersion $v) => [
                    'rating' => $v->getRating(),
                    'body' => $v->getBody(),
                    'editedAt' => $v->getEditedAt()?->format(\DateTimeInterface::ATOM),
                ],
                $review->getVersions()->toArray(),
            ),
        ];
    }
}
