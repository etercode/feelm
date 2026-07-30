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
        if ($rating < 0.5 || $rating > 5.0 || abs($rating * 2 - round($rating * 2)) >= 0.001) {
            throw new \InvalidArgumentException('invalid_rating');
        }

        $body = trim($body);
        if ('' === $body) {
            throw new \InvalidArgumentException('empty_body');
        }

        $review = $this->reviewRepository->findOneByUserAndWork($user, $work);
        if (null === $review) {
            $review = (new Review())
                ->setUser($user)
                ->setWork($work)
                ->setRating($rating)
                ->setBody($body);
            $this->entityManager->persist($review);
        } else {
            // Push the previous snapshot into history, then overwrite.
            $version = (new ReviewVersion())
                ->setRating($review->getRating())
                ->setBody((string) $review->getBody())
                ->setEditedAt($review->getUpdatedAt() ?? new \DateTimeImmutable());
            $review->addVersion($version);
            $review->setRating($rating)->setBody($body);
        }

        $this->entityManager->flush();

        return $review;
    }

    public function delete(User $user, Work $work): bool
    {
        $review = $this->reviewRepository->findOneByUserAndWork($user, $work);
        if (null === $review) {
            return false;
        }

        $this->entityManager->remove($review);
        $this->entityManager->flush();

        return true;
    }
}
