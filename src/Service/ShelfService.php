<?php

namespace App\Service;

use App\Entity\Entry;
use App\Entity\Work;
use App\Entity\User;
use App\Repository\EntryRepository;
use Doctrine\ORM\EntityManagerInterface;

class ShelfService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EntryRepository $entryRepository,
    ) {
    }

    /**
     * @param array{status?: string|null, rating?: float|null, progress?: array<string, mixed>|null} $data
     */
    public function upsert(User $user, Work $work, array $data): ?Entry
    {
        // Null status clears the shelf row entirely.
        if (\array_key_exists('status', $data) && null === $data['status']) {
            $existing = $this->entryRepository->findOneByUserAndWork($user, $work);
            if (null !== $existing) {
                $this->entityManager->remove($existing);
                $this->entityManager->flush();
            }

            return null;
        }

        $entry = $this->entryRepository->findOneByUserAndWork($user, $work);
        if (null === $entry) {
            $entry = (new Entry())
                ->setUser($user)
                ->setWork($work)
                ->setStatus($data['status'] ?? 'wishlist');
            $this->entityManager->persist($entry);
        }

        if (isset($data['status'])) {
            if (!\in_array($data['status'], Entry::STATUSES, true)) {
                throw new \InvalidArgumentException('invalid_status');
            }
            $entry->setStatus($data['status']);
        }

        if (\array_key_exists('rating', $data)) {
            $rating = $data['rating'];
            if (null !== $rating && !$this->isValidRating((float) $rating)) {
                throw new \InvalidArgumentException('invalid_rating');
            }
            $entry->setRating(null === $rating ? null : (float) $rating);
        }

        if (\array_key_exists('progress', $data)) {
            $entry->setProgress($this->normalizeProgress($work, $data['progress']));
        }

        $this->entityManager->flush();

        return $entry;
    }

    private function isValidRating(float $rating): bool
    {
        if ($rating < 0.5 || $rating > 5.0) {
            return false;
        }

        // Half-star steps only.
        return abs($rating * 2 - round($rating * 2)) < 0.001;
    }

    /**
     * @param array<string, mixed>|null $progress
     *
     * @return array<string, mixed>|null
     */
    private function normalizeProgress(Work $work, ?array $progress): ?array
    {
        if (null === $progress) {
            return null;
        }

        return match ($work->getType()) {
            'series' => [
                'season' => (int) ($progress['season'] ?? 1),
                'episode' => (int) ($progress['episode'] ?? 1),
            ],
            'game' => [
                'hours' => (float) ($progress['hours'] ?? 0),
            ],
            'book' => [
                'page' => (int) ($progress['page'] ?? 1),
            ],
            default => null,
        };
    }
}
