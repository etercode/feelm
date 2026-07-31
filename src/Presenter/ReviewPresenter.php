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

    /**
     * A review as the admin table sees it: the same fields, plus which title it
     * is about and whether it has been rewritten.
     *
     * The work arrives already presented rather than being reached for here.
     * Posters need the public URL generator, which is a service — and this
     * presenter is static, like every other one that does not.
     *
     * @param array<string, mixed>|null $work from WorkPresenter::compact()
     *
     * @return array<string, mixed>
     */
    public static function admin(Review $review, ?array $work = null): array
    {
        $versions = $review->getVersions();

        return [
            ...self::one($review),
            'work' => $work,
            'versionCount' => $versions->count(),
            'edited' => $versions->count() > 0,
        ];
    }

    /**
     * One row of the admin table.
     *
     * Deliberately does not touch getVersions(). The collection is lazy, so
     * reading it — even only to count it — is a query per row, and a page of
     * twenty-five reviews was twenty-five of them. The count arrives already
     * batched, and the wording history belongs on the review's own page rather
     * than in a table cell.
     *
     * @param array<string, mixed>|null $work from WorkPresenter::compact()
     *
     * @return array<string, mixed>
     */
    public static function adminRow(Review $review, ?array $work, int $versionCount): array
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
            'work' => $work,
            'versionCount' => $versionCount,
            'edited' => $versionCount > 0,
        ];
    }
}
