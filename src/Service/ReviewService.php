<?php

namespace App\Service;

use App\Entity\Work;
use App\Entity\Review;
use App\Entity\ReviewVersion;
use App\Entity\User;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;

class ReviewService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReviewRepository $reviewRepository,
    ) {
    }

    public function save(User $user, Work $work, float $rating, string $body): Review
    {
        [$rating, $body] = $this->validated($rating, $body);

        $review = $this->reviewRepository->findOneByUserAndWork($user, $work);
        if (null === $review) {
            $review = (new Review())
                ->setUser($user)
                ->setWork($work)
                ->setRating($rating)
                ->setBody($body);
            $this->entityManager->persist($review);
            $this->entityManager->flush();

            return $review;
        }

        return $this->rewrite($review, $rating, $body);
    }

    /**
     * Replaces the text of a review that already exists, keeping what it said
     * before.
     *
     * Split out from save() so a moderator editing somebody else's review goes
     * through exactly the same path: the previous wording is snapshotted into
     * history either way. A moderated review that quietly changed, with no
     * record of what it used to say, would be the worst version of this
     * feature.
     */
    public function rewrite(Review $review, float $rating, string $body): Review
    {
        [$rating, $body] = $this->validated($rating, $body);

        /*
         * Saving without changing anything is not an edit. Snapshotting it
         * anyway would fill the history with identical entries and make a
         * genuinely rewritten review harder to spot — which is the one thing
         * the history is for.
         */
        if ($body === $review->getBody() && abs($rating - $review->getRating()) < 0.001) {
            return $review;
        }

        $version = (new ReviewVersion())
            ->setRating($review->getRating())
            ->setBody((string) $review->getBody())
            ->setEditedAt($review->getUpdatedAt() ?? new \DateTimeImmutable());

        $review->addVersion($version);
        $review->setRating($rating)->setBody($body);

        $this->entityManager->flush();

        return $review;
    }

    public function delete(User $user, Work $work): bool
    {
        $review = $this->reviewRepository->findOneByUserAndWork($user, $work);
        if (null === $review) {
            return false;
        }

        return $this->remove($review);
    }

    /** Deletes a review outright, versions and all. There is no undo. */
    public function remove(Review $review): bool
    {
        $this->entityManager->remove($review);
        $this->entityManager->flush();

        return true;
    }

    /**
     * @return array{0: float, 1: string}
     *
     * @throws \InvalidArgumentException
     */
    private function validated(float $rating, string $body): array
    {
        // Half-stars only, which is what the star control can express.
        if ($rating < 0.5 || $rating > 5.0 || abs($rating * 2 - round($rating * 2)) >= 0.001) {
            throw new \InvalidArgumentException('invalid_rating');
        }

        $body = trim($body);
        if ('' === $body) {
            throw new \InvalidArgumentException('empty_body');
        }

        return [$rating, $body];
    }
}
